import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from './Contexts/AuthContext.jsx';
import MainLayout from './Layouts/MainLayout';
import Login from './Pages/Auth/Login.jsx';
import DashboardPage from './Pages/DashboardPage';
import TransactionLogsPage from './Pages/TransactionLogsPage';
import TerminalTokenPage from './Pages/TerminalTokenPage';
import UserManagementPage from './Pages/UserManagementPage';
import SystemLogsPage from './Pages/SystemLogsPage';
import IntakeHealthPage from './Pages/Observability/IntakeHealthPage.jsx';
import ProviderActivityPage from './Pages/Monitoring/ProviderActivityPage.jsx';
import PayloadSandboxPage from './Pages/PayloadSandboxPage.jsx';
import ProviderApiDocsPage from './Pages/ProviderApiDocsPage.jsx';
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

import ProtectedRoute from './Components/Auth/ProtectedRoute';
import UnauthorizedPage from './Pages/Auth/UnauthorizedPage';
import NotFoundPage from './Pages/Auth/NotFoundPage';
import { queryClient } from './lib/queryClient';

const App = () => {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Router>
          <Routes>
          {/* Public — no layout wrapper */}
          <Route path="/login" element={<Login />} />
          <Route path="/unauthorized" element={<UnauthorizedPage />} />
          <Route path="/sandbox/payload" element={<PayloadSandboxPage />} />
          <Route path="/docs/pos-provider/api-testing" element={<ProviderApiDocsPage />} />

          {/* Authenticated — wrapped in MainLayout and ProtectedRoute */}
          <Route
            path="/*"
            element={
              <ProtectedRoute>
                <MainLayout>
                  <Routes>
                    <Route path="/" element={<Navigate to="/dashboard" replace />} />

                    {/* Any Authenticated User */}
                    <Route path="/dashboard" element={<DashboardPage />} />
                    <Route path="/transactions" element={<TransactionLogsPage />} />
                    
                    {/* Admin / Manager / Commercial */}
                    <Route 
                      path="/terminal-tokens" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager', 'commercial']}>
                          <TerminalTokenPage />
                        </ProtectedRoute>
                      } 
                    />
                    <Route 
                      path="/users" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager']}>
                          <UserManagementPage />
                        </ProtectedRoute>
                      } 
                    />
                    <Route 
                      path="/system-logs" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager']}>
                          <SystemLogsPage />
                        </ProtectedRoute>
                      } 
                    />
                    <Route 
                      path="/observability/intake" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager']}>
                          <IntakeHealthPage />
                        </ProtectedRoute>
                      } 
                    />
                    <Route
                      path="/monitoring/activity"
                      element={
                        <ProtectedRoute roles={['admin', 'manager']}>
                          <ProviderActivityPage />
                        </ProtectedRoute>
                      }
                    />
                    {/* Finance Access */}
                    <Route 
                      path="/finance" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager', 'finance']}>
                          <FinanceDashboardPage />
                        </ProtectedRoute>
                      } 
                    />
                    <Route 
                      path="/reports" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                          <FinanceReportsPage />
                        </ProtectedRoute>
                      } 
                    />

                    {/* Commercial Access */}
                    <Route 
                      path="/commercial" 
                      element={
                        <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                          <CommercialDashboardPage />
                        </ProtectedRoute>
                      } 
                    />
                    <Route path="/commercial/reports" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <ReportsOverviewPage />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/hourly" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <HourlyReportPage />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/daily" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <SalesReportPage type="daily" />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/weekly" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <SalesReportPage type="weekly" />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/monthly" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <SalesReportPage type="monthly" />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/yearly" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <SalesReportPage type="yearly" />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/weekday" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <WeekdayReportPage />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/reports/weekend" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <WeekendReportPage />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/tenants" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <TenantDirectoryPage />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/tenants/manage" element={
                      <ProtectedRoute roles={['admin', 'manager']}>
                        <TenantUserManagementPage />
                      </ProtectedRoute>
                    } />
                    <Route path="/commercial/tenants/:uuid" element={
                      <ProtectedRoute roles={['admin', 'manager', 'finance', 'commercial']}>
                        <TenantProfilePage />
                      </ProtectedRoute>
                    } />

                    {/* Catch-all for unknown dashboard sub-routes */}
                    <Route path="*" element={<NotFoundPage />} />
                  </Routes>
                </MainLayout>
              </ProtectedRoute>
            }
          />
          {/* Catch-all for top-level unknown routes */}
          <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </Router>
      </AuthProvider>
    </QueryClientProvider>
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
