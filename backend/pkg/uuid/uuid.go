// Neura Cakrawira Solusi | SeWaroeng
// Package uuid provides UUID v7 (time-ordered) generation for all PKs and FKs.
package uuid

import (
	"crypto/rand"
	"encoding/binary"
	"fmt"
	"time"
)

// NewV7 generates a UUID version 7 (time-ordered).
// UUID v7 embeds a Unix timestamp in the most significant bits,
// making it sortable and reducing B-Tree index fragmentation.
//
// Format: tttttttt-tttt-7xxx-yxxx-xxxxxxxxxxxx
// where t = timestamp, x = random, y = variant bits.
func NewV7() (string, error) {
	var uuid [16]byte

	// Get current Unix time in milliseconds (48 bits)
	now := time.Now().UnixMilli()

	// Bytes 0-5: 48-bit big-endian Unix timestamp in milliseconds
	binary.BigEndian.PutUint32(uuid[0:4], uint32(now>>16))
	binary.BigEndian.PutUint16(uuid[4:6], uint16(now&0xFFFF))

	// Bytes 6-15: random
	if _, err := rand.Read(uuid[6:]); err != nil {
		return "", fmt.Errorf("uuid: failed to generate random bytes: %w", err)
	}

	// Set version 7 (bits 76–79 of byte 6)
	uuid[6] = (uuid[6] & 0x0F) | 0x70

	// Set variant bits (RFC 4122): bits 8–9 of byte 8 = 10
	uuid[8] = (uuid[8] & 0x3F) | 0x80

	return fmt.Sprintf("%08x-%04x-%04x-%04x-%012x",
		uuid[0:4],
		uuid[4:6],
		uuid[6:8],
		uuid[8:10],
		uuid[10:16],
	), nil
}

// MustNewV7 generates a UUID v7 and panics on error (use only in init contexts).
func MustNewV7() string {
	id, err := NewV7()
	if err != nil {
		panic(err)
	}
	return id
}

// Validate checks whether a string is a valid UUID format.
func Validate(s string) bool {
	if len(s) != 36 {
		return false
	}
	for i, c := range s {
		switch i {
		case 8, 13, 18, 23:
			if c != '-' {
				return false
			}
		default:
			if !isHex(byte(c)) {
				return false
			}
		}
	}
	return true
}

func isHex(c byte) bool {
	return (c >= '0' && c <= '9') ||
		(c >= 'a' && c <= 'f') ||
		(c >= 'A' && c <= 'F')
}
