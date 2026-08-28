import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import App from './App';
import {CartProvider} from './platform/cart';
import {AuthProvider} from './platform/auth';
import {UXProvider} from './components/UXProvider';
import './styles.scss';
import './accessibility.scss';

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
