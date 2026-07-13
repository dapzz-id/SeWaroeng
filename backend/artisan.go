// Neura Cakrawira Solusi | SeWaroeng
// artisan.go — Entry point for SeWaroeng Goravel Backend Artisan CLI.
// Run with: go run artisan.go [command]

//go:build ignore

package main

import (
	"os"

	"github.com/goravel/framework/facades"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/bootstrap"
)

func main() {
	// Initialize the Goravel framework.
	bootstrap.Boot()

	// Start Artisan console commands.
	facades.Artisan().Run(os.Args, true)
}
