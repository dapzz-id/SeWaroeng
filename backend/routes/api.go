// Neura Cakrawira Solusi | SeWaroeng
// API Route registration — groups all SeWaroeng API endpoints.
package routes

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/goravel/framework/contracts/route"
	"github.com/goravel/framework/facades"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/app/providers"
)

// RegisterAPI registers all API route groups with their middleware chains.
// Dependencies are resolved from providers.App, which is populated by AppServiceProvider.Boot().
func RegisterAPI() {
	app := providers.App

	// Handlers and middlewares from DI container
	authMiddleware := app.AuthMiddleware.(http.Middleware)
	tenantMiddleware := app.TenantMiddleware.(http.Middleware)
	rateLimitMiddleware := app.RateLimitMiddleware.(http.Middleware)
	roleSuperadmin := app.RoleSuperadminMiddleware.(http.Middleware)
	roleSeller := app.RoleSellerMiddleware.(http.Middleware)
	roleBuyer := app.RoleBuyerMiddleware.(http.Middleware)

	facades.Route().Prefix("/api/v1").Group(func(router route.Router) {
		// ── Public routes (no authentication required) ─────────────────────────
		router.Prefix("/public").Group(func(pub route.Router) {
			pub.Get("/stores/{store_id}/products", app.ProductHandler.PublicList)
			pub.Get("/products/{id}", app.ProductHandler.Get)
			pub.Get("/stores/{store_id}/crowd-level", app.BookingHandler.GetCrowdLevel)
		})

		// ── Auth routes (rate limited, no auth token required) ─────────────────
		router.Prefix("/auth").Middleware(rateLimitMiddleware).Group(func(auth route.Router) {
			auth.Post("/register", app.AuthHandler.Register)
			auth.Post("/login", app.AuthHandler.Login)
			auth.Post("/refresh", app.AuthHandler.Refresh)
		})

		// ── Auth routes requiring existing token ───────────────────────────────
		router.Prefix("/auth").Middleware(authMiddleware).Group(func(auth route.Router) {
			auth.Delete("/logout", app.AuthHandler.Logout)
			auth.Get("/sessions", app.AuthHandler.ListSessions)
			auth.Delete("/sessions/{id}", app.AuthHandler.RevokeSession)
		})

		// ── Protected routes ───────────────────────────────────────────────────
		router.Middleware(authMiddleware, tenantMiddleware).Group(func(api route.Router) {
			// Wallet
			api.Get("/wallet", app.WalletHandler.GetBalance)
			api.Post("/wallet/topup", app.WalletHandler.TopUp)
			api.Get("/wallet/transactions", app.WalletHandler.ListTransactions)

			// Products (tenant-scoped)
			api.Get("/products", app.ProductHandler.List)
			api.Post("/products", app.ProductHandler.Create)
			api.Get("/products/{id}", app.ProductHandler.Get)
			api.Put("/products/{id}", app.ProductHandler.Update)
			api.Delete("/products/{id}", app.ProductHandler.Delete)

			// Orders
			api.Post("/orders", app.OrderHandler.Create)
			api.Get("/orders", app.OrderHandler.List)
			api.Get("/orders/{id}", app.OrderHandler.Get)
			api.Post("/orders/{id}/cancel", app.OrderHandler.Cancel)
			api.Put("/orders/{id}/status", app.OrderHandler.UpdateStatus)

			// Shipping
			api.Post("/shipping/calculate", app.ShippingHandler.Calculate)
			api.Post("/store/couriers", app.ShippingHandler.CreateCourier)

			// Store management (Owner/Staff)
			api.Get("/store/profile", app.TenantHandler.GetProfile)
			api.Put("/store/profile", app.TenantHandler.UpdateProfile)

			// Users (Owner only)
			api.Get("/users", app.UserHandler.List)
			api.Post("/users/staff", app.UserHandler.CreateStaff)
			api.Delete("/users/{id}", app.UserHandler.Delete)

			// Customer addresses
			api.Get("/customers/addresses", app.UserHandler.ListAddresses)
			api.Post("/customers/addresses", app.UserHandler.CreateAddress)
			api.Put("/customers/addresses/{id}", app.UserHandler.UpdateAddress)
			api.Delete("/customers/addresses/{id}", app.UserHandler.DeleteAddress)

			// POS / Cashier
			api.Post("/pos/transactions", app.POSHandler.CreateTransaction)
			api.Post("/pos/sync", app.POSHandler.SyncOffline)

			// Profile & Sessions
			api.Get("/profile", app.UserHandler.GetProfile)
			api.Put("/profile", app.UserHandler.UpdateProfile)

			// Bookings (Buyer & Seller)
			api.Prefix("/bookings").Middleware(roleBuyer).Group(func(buyer route.Router) {
				buyer.Post("/", app.BookingHandler.CreateBooking)
			})

			api.Prefix("/store/bookings").Middleware(roleSeller).Group(func(seller route.Router) {
				seller.Get("/", app.BookingHandler.ListBookings)
				seller.Put("/{id}/approve", app.BookingHandler.ApproveBooking)
				seller.Put("/{id}/reject", app.BookingHandler.RejectBooking)
			})

			// Seller & Financial Reports
			api.Prefix("/reports").Middleware(roleSeller).Group(func(seller route.Router) {
				seller.Get("/monthly", app.ReportHandler.GetMonthlyReport)
				seller.Get("/ai-insights", app.ReportHandler.GetAIInsights)
			})

			// Subscription Management
			api.Prefix("/subscription").Middleware(roleSeller).Group(func(seller route.Router) {
				seller.Get("/", app.SubscriptionHandler.GetStatus)
				seller.Post("/subscribe", app.SubscriptionHandler.Subscribe)
				seller.Post("/renew", app.SubscriptionHandler.Renew)
				seller.Get("/token-quota", app.SubscriptionHandler.GetTokenQuota)
			})

			// Notifications (List is accessible by authenticated users, creation requires superadmin)
			api.Get("/notifications", app.NotificationHandler.ListNotifications)

			api.Prefix("/admin").Middleware(roleSuperadmin).Group(func(admin route.Router) {
				admin.Post("/notifications", app.NotificationHandler.BroadcastNotification)
				admin.Post("/notifications/maintenance", app.NotificationHandler.MaintenanceAlert)
				admin.Post("/promo", app.NotificationHandler.CreatePromo)
			})
		})

		// ── Webhooks (signature-verified, no JWT auth) ────────────────────────
		router.Prefix("/webhooks").Group(func(wh route.Router) {
			wh.Post("/midtrans", app.WebhookHandler.Midtrans)
			wh.Post("/paypal", app.WebhookHandler.PayPal)
			wh.Post("/wise", app.WebhookHandler.Wise)
		})
	})
}
