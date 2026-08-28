// P1-C compatibility surface: keep the existing named route-module contract while
// delegating all live Systems business state to the Laravel/API-authoritative implementation.
// Residual client-authority retirement is owned by the active P1-D boundary.
export {
	Orders,
	Checkout,
	Tracking,
	Wallet,
	Notifications,
	Messages,
	Settings,
	Gifts,
	AdminControl,
	ReturnsCenter,
	SavedAlerts,
	OperationsCenter,
	SellerQuality,
} from "./SystemsServer";
