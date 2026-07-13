// Neura Cakrawira Solusi | SeWaroeng
// main.go — Entry point for SeWaroeng Goravel Backend HTTP server.
package main

import (
	"github.com/goravel/framework/facades"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/bootstrap"
)

func main() {
	// Initialize the Goravel framework and register service providers.
	bootstrap.Boot()

	// Start the HTTP server.
	go func() {
		if err := facades.Route().Run(); err != nil {
			facades.Log().Errorf("Route run error: %v", err)
		}
	}()

	select {}
}
