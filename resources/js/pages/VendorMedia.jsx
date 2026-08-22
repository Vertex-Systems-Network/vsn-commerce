import SEO from '../components/SEO';
import MediaLibraryPanel from '../components/MediaLibraryPanel';

/** Renders the seller-owned reusable media library. */
export default function VendorMedia(){return <><SEO title="Media Library | Seller Center | VSN Ecommerce"/><div className="simple-page seller-center-page"><div className="page-title"><span>SELLER MEDIA</span><h1>Media library</h1><p>Upload once, reuse across your products, and keep every seller's files isolated.</p></div><MediaLibraryPanel mode="vendor"/></div></>}
