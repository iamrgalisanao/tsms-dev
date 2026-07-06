import React from 'react';
import { getTenantHeaderDetails } from './TenantTableHeaderMeta';

const TenantTableHeaderRow = ({ colSpan, selectedTenant = null, tenantId = '', tenants = [] }) => {
    const { tenantName, customerCode } = getTenantHeaderDetails({ selectedTenant, tenantId, tenants });

    return (
        <tr>
            <th
                colSpan={colSpan}
                style={{
                    padding: '12px 20px',
                    background: '#f1f5f9',
                    borderBottom: '1px solid #e2e8f0',
                    textAlign: 'left',
                    color: '#334155',
                }}
            >
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16, alignItems: 'center' }}>
                    <span style={{ fontSize: 10, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#64748b' }}>
                        Tenant Name:
                        <span style={{ marginLeft: 6, color: '#0f172a' }}>{tenantName}</span>
                    </span>
                    <span style={{ fontSize: 10, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#64748b' }}>
                        Customer Code:
                        <span style={{ marginLeft: 6, color: '#0f172a' }}>{customerCode}</span>
                    </span>
                </div>
            </th>
        </tr>
    );
};

export default TenantTableHeaderRow;
