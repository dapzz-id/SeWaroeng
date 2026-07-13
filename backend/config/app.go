// Neura Cakrawira Solusi | SeWaroeng
// config/app.go — Main configuration registration.
package config

import (
	"github.com/goravel/framework/facades"
	"github.com/goravel/framework/contracts/database/driver"
	contractroute "github.com/goravel/framework/contracts/route"
	"github.com/goravel/postgres"
	"github.com/goravel/gin"
)

func Boot() {
	// Initialize default configuration
	facades.Config().Add("app", map[string]any{
		"name":     facades.Config().Env("APP_NAME", "SeWaroeng"),
		"env":      facades.Config().Env("APP_ENV", "local"),
		"debug":    facades.Config().Env("APP_DEBUG", true),
		"url":      facades.Config().Env("APP_URL", "http://localhost:3000"),
		"timezone": "UTC",
	})
	
	facades.Config().Add("database", map[string]any{
		"default": facades.Config().Env("DB_DRIVER", "postgres"),
		"connections": map[string]any{
			"postgres": map[string]any{
				"driver":   "postgres",
				"host":     facades.Config().Env("DB_HOST", "127.0.0.1"),
				"port":     facades.Config().Env("DB_PORT", "5432"),
				"database": facades.Config().Env("DB_DATABASE", "sewaroeng_db"),
				"username": facades.Config().Env("DB_USERNAME", "sewaroeng_user"),
				"password": facades.Config().Env("DB_PASSWORD", "sewaroeng_secret"),
				"sslmode":  facades.Config().Env("DB_SSLMODE", "disable"),
				"timezone": "UTC",
				"via": func() (driver.Driver, error) {
					makeResult, err := facades.App().MakeWith(postgres.Binding, map[string]any{
						"connection": "postgres",
					})
					if err != nil {
						return nil, err
					}
					return makeResult.(driver.Driver), nil
				},
			},
		},
		"migrations": "migrations",
	})

	facades.Config().Add("http", map[string]any{
		"default": "gin",
		"drivers": map[string]any{
			"gin": map[string]any{
				"route": func() (contractroute.Route, error) {
					makeResult, err := facades.App().MakeWith(gin.BindingRoute, map[string]any{
						"driver": "gin",
					})
					if err != nil {
						return nil, err
					}
					return makeResult.(contractroute.Route), nil
				},
			},
		},
		"url":  facades.Config().Env("APP_URL", "http://localhost:3000"),
		"host": facades.Config().Env("APP_HOST", "127.0.0.1"),
		"port": facades.Config().Env("APP_PORT", "3000"),
	})
}
