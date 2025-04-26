import React from 'react';
import { createRoot } from 'react-dom/client';
import './bootstrap';
import '../css/app.css';
import Dashboard from './components/Dashboard';

const container = document.getElementById('app');
if (container) {
    const root = createRoot(container);
    root.render(React.createElement(Dashboard));
}
