import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import MainLayout from './Layouts/MainLayout';
import DashboardPage from './Pages/DashboardPage';
import './bootstrap';
import '../css/app.css';

const App = () => {
  return (
    <Router>
      <MainLayout>
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          {/* Add other routes as they are migrated */}
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="*" element={<div className="p-8 text-center text-gray-500">Feature coming soon...</div>} />
        </Routes>
      </MainLayout>
    </Router>
  );
};

const container = document.getElementById('app');
if (container) {
  const root = createRoot(container);
  root.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
