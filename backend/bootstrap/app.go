// Neura Cakrawira Solusi | SeWaroeng
// bootstrap/app.go — Bootstraps the Goravel framework and registers providers.
package bootstrap

import (
	contractfoundation "github.com/goravel/framework/contracts/foundation"
	"github.com/goravel/framework/foundation"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/config"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/routes"

	// Include core Goravel providers
	"github.com/goravel/framework/database"
	"github.com/goravel/framework/log"
	"github.com/goravel/framework/route"

	// SeWaroeng app provider
	"github.com/neuracakrawirasolusi/sewaroeng-backend/app/providers"

	"github.com/goravel/postgres"
	"github.com/goravel/gin"
)

func Boot() {
	app := foundation.NewApplication()

	// Bootstrap the application using ApplicationBuilder (Goravel v1.14+)
	app.SetBuilder(foundation.NewApplicationBuilder(app).
		WithConfig(config.Boot).
		WithRouting(routes.RegisterAPI).
		WithProviders(func() []contractfoundation.ServiceProvider {
			return []contractfoundation.ServiceProvider{
				&database.ServiceProvider{},
				&log.ServiceProvider{},
				&route.ServiceProvider{},
				&postgres.ServiceProvider{},
				&gin.ServiceProvider{},
				// SeWaroeng app provider — wires all module dependencies
				&providers.AppServiceProvider{},
			}
		}),
	)

	// Register config
	app.Boot()
	app.Build()
}
