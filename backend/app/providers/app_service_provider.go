// Neura Cakrawira Solusi | SeWaroeng
// AppServiceProvider — wires all SeWaroeng module dependencies.
// This is the composition root: all modules, repos, services, and handlers are built here.
package providers

import (
	"os"
	"strconv"

	contractfoundation "github.com/goravel/framework/contracts/foundation"
	"github.com/goravel/framework/facades"

	contractseeder "github.com/goravel/framework/contracts/database/seeder"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/database/seeders"
	redisinf "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/infrastructure/redis"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/middleware"
	pkgjwt "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/jwt"

	// Auth module
	authhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/handler"
	authrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/repository"
	authsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/service"

	// Product module
	prodhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/handler"
	prodrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/repository"
	prodsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/service"

	// Order module
	ordhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/order/handler"
	ordsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/order/service"

	// Tenant module
	tenhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/tenant/handler"
	tenrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/tenant/repository"
	tensvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/tenant/service"

	// User module
	usrhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/user/handler"
	usrrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/user/repository"
	usrsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/user/service"

	// Wallet module
	walhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/wallet/handler"
	walrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/wallet/repository"
	walsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/wallet/service"

	// Shipping module
	shiphdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/shipping/handler"
	shipsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/shipping/service"

	// POS module
	poshdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/pos/handler"
	possvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/pos/service"

	// Payment module
	payhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/payment/handler"

	// Booking module
	bookhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/booking/handler"
	bookrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/booking/repository"
	booksvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/booking/service"

	// Report module
	rpthdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/report/handler"
	rptrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/report/repository"
	rptsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/report/service"

	// Subscription module
	subhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/subscription/handler"
	subrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/subscription/repository"
	subsvc "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/subscription/service"

	// Notification module
	notifhdl "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/notification/handler"
)

// App is the global singleton holding all wired-up dependencies.
var App *Container

// Container holds all resolved dependencies.
type Container struct {
	Redis *redisinf.Client
	JWT   *pkgjwt.Manager

	// Middlewares
	AuthMiddleware           http_middleware
	TenantMiddleware         http_middleware
	RateLimitMiddleware      http_middleware
	RoleSuperadminMiddleware http_middleware
	RoleSellerMiddleware     http_middleware
	RoleBuyerMiddleware      http_middleware

	// Handlers
	AuthHandler         *authhdl.AuthHandler
	ProductHandler      *prodhdl.ProductHandler
	OrderHandler        *ordhdl.OrderHandler
	TenantHandler       *tenhdl.TenantHandler
	UserHandler         *usrhdl.UserHandler
	WalletHandler       *walhdl.WalletHandler
	ShippingHandler     *shiphdl.ShippingHandler
	POSHandler          *poshdl.POSHandler
	WebhookHandler      *payhdl.WebhookHandler
	BookingHandler      *bookhdl.BookingHandler
	ReportHandler       *rpthdl.ReportHandler
	SubscriptionHandler *subhdl.SubscriptionHandler
	NotificationHandler *notifhdl.NotificationHandler
}

// http_middleware is an alias for the concrete middleware type for container storage.
type http_middleware = interface{ Signature() string }

// AppServiceProvider implements foundation.ServiceProvider.
type AppServiceProvider struct{}

func (p *AppServiceProvider) Register(app contractfoundation.Application) {
	// Registration happens first; Boot() wires everything when config is ready.
}

func (p *AppServiceProvider) Boot(app contractfoundation.Application) {
	db := facades.Orm()

	// ── 1. Infrastructure ─────────────────────────────────────────────────
	redisHost := env("REDIS_HOST", "127.0.0.1")
	redisPort, _ := strconv.Atoi(env("REDIS_PORT", "6379"))
	redisPass := env("REDIS_PASSWORD", "")
	redisDB, _ := strconv.Atoi(env("REDIS_DATABASE", "0"))

	redisClient, err := redisinf.New(redisinf.Config{
		Host:     redisHost,
		Port:     redisPort,
		Password: redisPass,
		DB:       redisDB,
	})
	if err != nil {
		facades.Log().Errorf("AppServiceProvider: failed to connect Redis: %v", err)
		// Application can still run without Redis — middleware will degrade gracefully
	}

	jwtSecret := env("JWT_SECRET", "insecure-change-me")
	jwtTTL, _ := strconv.Atoi(env("JWT_TTL", "15"))
	jwtManager := pkgjwt.NewManager(jwtSecret, jwtTTL)

	// ── 2. Middlewares ────────────────────────────────────────────────────
	authMiddleware := middleware.NewAuthMiddleware(jwtManager, redisClient)
	tenantMiddleware := middleware.NewTenantMiddleware()
	rateLimitMiddleware := middleware.NewRateLimitAuthMiddleware(redisClient)
	roleSuperadmin := middleware.NewRoleMiddleware("superadmin")
	roleSeller := middleware.NewRoleMiddleware("seller", "superadmin") // Superadmin can also act as seller implicitly
	roleBuyer := middleware.NewRoleMiddleware("buyer")

	// ── 3. Repositories ───────────────────────────────────────────────────
	authRepo := authrepo.NewAuthRepository(db)
	prodRepo := prodrepo.NewProductRepository(db)
	tenRepo := tenrepo.NewTenantRepository(db)
	usrRepo := usrrepo.NewUserRepository(db)
	walRepo := walrepo.NewWalletRepository(db)
	bookRepo := bookrepo.NewBookingRepository(db)
	rptRepo := rptrepo.NewReportRepository(db)
	subRepo := subrepo.NewSubscriptionRepository(db)

	// ── 4. Services ───────────────────────────────────────────────────────
	authService := authsvc.NewAuthService(authRepo, redisClient, jwtManager, db)
	prodService := prodsvc.NewProductService(prodRepo, redisClient)
	ordService := ordsvc.NewOrderService(db, prodRepo)
	tenService := tensvc.NewTenantService(tenRepo)
	usrService := usrsvc.NewUserService(usrRepo)
	walService := walsvc.NewWalletService(db, walRepo)
	shipService := shipsvc.NewShippingService(db)
	posService := possvc.NewPOSService(db)
	bookService := booksvc.NewBookingService(bookRepo, tenRepo, db)
	rptService := rptsvc.NewReportService(rptRepo, db)
	subService := subsvc.NewSubscriptionService(subRepo)

	// ── 5. Handlers ───────────────────────────────────────────────────────
	App = &Container{
		Redis: redisClient,
		JWT:   jwtManager,

		AuthMiddleware:           authMiddleware,
		TenantMiddleware:         tenantMiddleware,
		RateLimitMiddleware:      rateLimitMiddleware,
		RoleSuperadminMiddleware: roleSuperadmin,
		RoleSellerMiddleware:     roleSeller,
		RoleBuyerMiddleware:      roleBuyer,

		AuthHandler:         authhdl.NewAuthHandler(authService),
		ProductHandler:      prodhdl.NewProductHandler(prodService),
		OrderHandler:        ordhdl.NewOrderHandler(ordService),
		TenantHandler:       tenhdl.NewTenantHandler(tenService),
		UserHandler:         usrhdl.NewUserHandler(usrService),
		WalletHandler:       walhdl.NewWalletHandler(walService),
		ShippingHandler:     shiphdl.NewShippingHandler(shipService),
		POSHandler:          poshdl.NewPOSHandler(posService),
		WebhookHandler:      payhdl.NewWebhookHandler(),
		BookingHandler:      bookhdl.NewBookingHandler(bookService),
		ReportHandler:       rpthdl.NewReportHandler(rptService),
		SubscriptionHandler: subhdl.NewSubscriptionHandler(subService),
		NotificationHandler: notifhdl.NewNotificationHandler(),
	}

	facades.Seeder().Register([]contractseeder.Seeder{
		&seeders.DatabaseSeeder{},
		&seeders.TenantSeeder{},
		&seeders.UserSeeder{},
		&seeders.ProductSeeder{},
	})

	facades.Log().Infof("AppServiceProvider: all modules wired successfully")
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
