import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import App from './App';
import {CartProvider} from './platform/cart';
import {AuthProvider} from './platform/auth';
import {sanitizeRedirectSearch} from './platform/navigation';
import {UXProvider} from './components/UXProvider';
import './styles.scss';
import './accessibility.scss';

const safeSearch = sanitizeRedirectSearch(window.location.search);
if (safeSearch !== window.location.search) {
  window.history.replaceState(
    window.history.state,
    '',
    `${window.location.pathname}${safeSearch}${window.location.hash}`,
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <UXProvider>
      <AuthProvider>
        <CartProvider>
          <App/>
        </CartProvider>
      </AuthProvider>
      </UXProvider>
    </BrowserRouter>
  </React.StrictMode>
);
