import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import App from './App';
import {StoreProvider} from './platform/store';
import {CartProvider} from './platform/cart';
import {AuthProvider} from './platform/auth';
import {UXProvider} from './components/UXProvider';
import './styles.scss';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <UXProvider>
      <AuthProvider>
        <StoreProvider>
          <CartProvider>
            <App/>
          </CartProvider>
        </StoreProvider>
      </AuthProvider>
      </UXProvider>
    </BrowserRouter>
  </React.StrictMode>
);
