import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './Contexts/AuthContext.jsx';
import MainLayout from './Layouts/MainLayout';
import Login from './Pages/Auth/Login.jsx';
import DashboardPage from './Pages/DashboardPage';
import TransactionLogsPage from './Pages/TransactionLogsPage';
import TerminalTokenPage from './Pages/TerminalTokenPage';
import UserManagementPage from './Pages/UserManagementPage';
import SystemLogsPage from './Pages/SystemLogsPage';
// Finance
import FinanceDashboardPage from './Pages/Finance/FinanceDashboardPage.jsx';
import FinanceReportsPage from './Pages/Finance/FinanceReportsPage.jsx';
// Commercial
import CommercialDashboardPage from './Pages/Commercial/CommercialDashboardPage.jsx';
import ReportsOverviewPage from './Pages/Commercial/ReportsOverviewPage.jsx';
import HourlyReportPage from './Pages/Commercial/HourlyReportPage.jsx';
import SalesReportPage from './Pages/Commercial/SalesReportPage.jsx';
import WeekdayReportPage from './Pages/Commercial/WeekdayReportPage.jsx';
import WeekendReportPage from './Pages/Commercial/WeekendReportPage.jsx';
import TenantDirectoryPage from './Pages/Commercial/TenantDirectoryPage.jsx';
import TenantProfilePage from './Pages/Commercial/TenantProfilePage.jsx';
import TenantUserManagementPage from './Pages/Commercial/TenantUserManagementPage.jsx';
import './bootstrap';
import '../css/app.css';

const App = () => {
  return (
    <AuthProvider>
      <Router>
        <Routes>
          {/* Public — no layout wrapper */}
          <Route path="/login" element={<Login />} />

          {/* Authenticated — wrapped in MainLayout */}
          <Route
            path="/*"
            element={
              <MainLayout>
                <Routes>
                  <Route path="/" element={<Navigate to="/dashboard" replace />} />

                  {/* Admin / default */}
                  <Route path="/dashboard" element={<DashboardPage />} />
                  <Route path="/transactions" element={<TransactionLogsPage />} />
                  <Route path="/terminal-tokens" element={<TerminalTokenPage />} />
                  <Route path="/users" element={<UserManagementPage />} />
                  <Route path="/system-logs" element={<SystemLogsPage />} />

                  {/* Finance */}
                  <Route path="/finance" element={<FinanceDashboardPage />} />
                  <Route path="/reports" element={<FinanceReportsPage />} />

                  {/* Commercial */}
                  <Route path="/commercial" element={<CommercialDashboardPage />} />
                  <Route path="/commercial/reports" element={<ReportsOverviewPage />} />
                  <Route path="/commercial/reports/hourly" element={<HourlyReportPage />} />
                  <Route path="/commercial/reports/daily" element={<SalesReportPage type="daily" />} />
                  <Route path="/commercial/reports/weekly" element={<SalesReportPage type="weekly" />} />
                  <Route path="/commercial/reports/monthly" element={<SalesReportPage type="monthly" />} />
                  <Route path="/commercial/reports/yearly" element={<SalesReportPage type="yearly" />} />
                  <Route path="/commercial/reports/weekday" element={<WeekdayReportPage />} />
                  <Route path="/commercial/reports/weekend" element={<WeekendReportPage />} />
                  <Route path="/commercial/tenants" element={<TenantDirectoryPage />} />
                  <Route path="/commercial/tenants/manage" element={<TenantUserManagementPage />} />
                  <Route path="/commercial/tenants/:id" element={<TenantProfilePage />} />

                  <Route path="*" element={<div className="p-8 text-center text-gray-500">Feature coming soon...</div>} />
                </Routes>
              </MainLayout>
            }
          />
        </Routes>
      </Router>
    </AuthProvider>
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
