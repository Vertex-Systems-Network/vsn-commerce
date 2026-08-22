import SEO from '../components/SEO';
import MediaLibraryPanel from '../components/MediaLibraryPanel';

/** Renders the marketplace-wide reusable media library for administrators. */
export default function AdminMedia(){return <><SEO title="Media Library | VSN Ecommerce"/><main className="simple-page admin-media-page"><div className="page-title"><span>ADMIN MEDIA</span><h1>Media library</h1><p>Upload, search and reuse marketplace or seller-scoped images instead of pasting image URLs repeatedly.</p></div><MediaLibraryPanel mode="admin"/></main></>}
