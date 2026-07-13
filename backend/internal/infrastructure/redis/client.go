// Neura Cakrawira Solusi | SeWaroeng
// Package redis provides a Redis client wrapper for caching, sessions, and rate limiting.
package redis

import (
	"context"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

// Client wraps go-redis with SeWaroeng-specific helper methods.
type Client struct {
	rdb *redis.Client
}

// Config holds Redis connection configuration.
type Config struct {
	Host     string
	Port     int
	Password string
	DB       int
}

// New creates a new Redis client and verifies connectivity.
func New(cfg Config) (*Client, error) {
	rdb := redis.NewClient(&redis.Options{
		Addr:         fmt.Sprintf("%s:%d", cfg.Host, cfg.Port),
		Password:     cfg.Password,
		DB:           cfg.DB,
		PoolSize:     20,
		MinIdleConns: 5,
		DialTimeout:  5 * time.Second,
		ReadTimeout:  3 * time.Second,
		WriteTimeout: 3 * time.Second,
	})

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := rdb.Ping(ctx).Err(); err != nil {
		return nil, fmt.Errorf("redis: connection failed: %w", err)
	}

	return &Client{rdb: rdb}, nil
}

// ─── Generic Cache Operations ─────────────────────────────────────────────────

// Set stores a value with a TTL.
func (c *Client) Set(ctx context.Context, key, value string, ttl time.Duration) error {
	return c.rdb.Set(ctx, key, value, ttl).Err()
}

// Get retrieves a value by key. Returns ("", redis.Nil) if not found.
func (c *Client) Get(ctx context.Context, key string) (string, error) {
	return c.rdb.Get(ctx, key).Result()
}

// Del deletes one or more keys.
func (c *Client) Del(ctx context.Context, keys ...string) error {
	return c.rdb.Del(ctx, keys...).Err()
}

// Exists checks if a key exists.
func (c *Client) Exists(ctx context.Context, key string) (bool, error) {
	n, err := c.rdb.Exists(ctx, key).Result()
	return n > 0, err
}

// Expire updates the TTL of an existing key.
func (c *Client) Expire(ctx context.Context, key string, ttl time.Duration) error {
	return c.rdb.Expire(ctx, key, ttl).Err()
}

// TTL returns remaining TTL of a key.
func (c *Client) TTL(ctx context.Context, key string) (time.Duration, error) {
	return c.rdb.TTL(ctx, key).Result()
}

// Keys returns all keys matching a pattern (use with care in production).
func (c *Client) Keys(ctx context.Context, pattern string) ([]string, error) {
	return c.rdb.Keys(ctx, pattern).Result()
}

// ─── Session Operations ───────────────────────────────────────────────────────

// SessionKey returns the Redis key for a session.
func SessionKey(userID, sessionID string) string {
	return fmt.Sprintf("session:%s:%s", userID, sessionID)
}

// SetSession stores session metadata (JSON string) with TTL.
func (c *Client) SetSession(ctx context.Context, userID, sessionID, metadata string, ttl time.Duration) error {
	return c.Set(ctx, SessionKey(userID, sessionID), metadata, ttl)
}

// GetSession retrieves session metadata.
func (c *Client) GetSession(ctx context.Context, userID, sessionID string) (string, error) {
	return c.Get(ctx, SessionKey(userID, sessionID))
}

// DeleteSession removes a session (force logout).
func (c *Client) DeleteSession(ctx context.Context, userID, sessionID string) error {
	return c.Del(ctx, SessionKey(userID, sessionID))
}

// GetUserSessions returns all active session keys for a user.
func (c *Client) GetUserSessions(ctx context.Context, userID string) ([]string, error) {
	return c.Keys(ctx, fmt.Sprintf("session:%s:*", userID))
}

// ─── Cache Key Helpers ────────────────────────────────────────────────────────

// CatalogKey returns the cache key for a product catalog page.
func CatalogKey(storeID string, page, limit int) string {
	return fmt.Sprintf("catalog:%s:%d:%d", storeID, page, limit)
}

// StoreCachePattern returns the wildcard pattern for all cache keys of a store.
func StoreCachePattern(storeID string) string {
	return fmt.Sprintf("catalog:%s:*", storeID)
}

// InvalidateStoreCatalog deletes all catalog cache entries for a store.
func (c *Client) InvalidateStoreCatalog(ctx context.Context, storeID string) error {
	keys, err := c.Keys(ctx, StoreCachePattern(storeID))
	if err != nil {
		return err
	}
	if len(keys) == 0 {
		return nil
	}
	return c.Del(ctx, keys...)
}

// ─── Rate Limiting (Token Bucket via Lua) ─────────────────────────────────────

// tokenBucketLua implements atomic Token Bucket algorithm.
// Args: [key, max_tokens, refill_window_seconds]
// Returns: [allowed (1/0), remaining_tokens, retry_after_seconds]
var tokenBucketLua = redis.NewScript(`
local key         = KEYS[1]
local max_tokens  = tonumber(ARGV[1])
local window_secs = tonumber(ARGV[2])
local now         = tonumber(redis.call("TIME")[1])

local data = redis.call("HMGET", key, "tokens", "last_refill")
local tokens     = tonumber(data[1]) or max_tokens
local last_refill = tonumber(data[2]) or now

-- Refill: if window has passed, restore tokens
if now - last_refill >= window_secs then
    tokens = max_tokens
    last_refill = now
end

if tokens > 0 then
    tokens = tokens - 1
    redis.call("HMSET", key, "tokens", tokens, "last_refill", last_refill)
    redis.call("EXPIRE", key, window_secs * 2)
    return {1, tokens, 0}
else
    local retry_after = window_secs - (now - last_refill)
    return {0, 0, retry_after}
end
`)

// RateLimitResult holds Token Bucket check result.
type RateLimitResult struct {
	Allowed    bool
	Remaining  int
	RetryAfter int // seconds
}

// CheckRateLimit performs an atomic Token Bucket check for the given key.
func (c *Client) CheckRateLimit(ctx context.Context, key string, maxTokens, windowSecs int) (*RateLimitResult, error) {
	res, err := tokenBucketLua.Run(ctx, c.rdb,
		[]string{fmt.Sprintf("ratelimit:%s", key)},
		maxTokens, windowSecs,
	).Int64Slice()
	if err != nil {
		return nil, fmt.Errorf("redis: rate limit script error: %w", err)
	}

	return &RateLimitResult{
		Allowed:    res[0] == 1,
		Remaining:  int(res[1]),
		RetryAfter: int(res[2]),
	}, nil
}

// ─── Underlying Client Access ─────────────────────────────────────────────────

// Raw returns the underlying *redis.Client for advanced operations.
func (c *Client) Raw() *redis.Client {
	return c.rdb
}

// Close closes the Redis connection.
func (c *Client) Close() error {
	return c.rdb.Close()
}
