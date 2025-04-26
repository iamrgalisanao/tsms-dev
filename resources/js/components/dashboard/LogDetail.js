/** @jsxRuntime classic */
/** @jsx React.createElement */
import React, { useState } from 'react';

const LogDetail = ({ log, onClose }) => {
    const [activeTab, setActiveTab] = useState('overview');
    
    if (!log) return null;
    
    const formatJson = (jsonData) => {
        if (!jsonData) return 'No data';
        
        let formattedData;
        
        if (typeof jsonData === 'string') {
            try {
                formattedData = JSON.stringify(JSON.parse(jsonData), null, 2);
            } catch (e) {
                formattedData = jsonData;
            }
        } else {
            formattedData = JSON.stringify(jsonData, null, 2);
        }
        
        return formattedData;
    };
    
    const renderOverviewTab = () => React.createElement(
        'div',
        null,
        React.createElement(
            'div',
            { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' },
            React.createElement(
                'div',
                null,
                React.createElement('h3', { className: 'font-semibold mb-2' }, 'Transaction Details'),
                React.createElement(
                    'table',
                    { className: 'w-full text-sm' },
                    React.createElement(
                        'tbody',
                        null,
                        [
                            ['ID', log.id],
                            ['Transaction ID', log.transaction_id || 'N/A'],
                            ['Status', log.status],
                            ['Validation Status', log.validation_status || 'N/A'],
                            ['Terminal ID', log.terminal_id || 'N/A'],
                            ['Tenant ID', log.tenant_id || 'N/A']
                        ].map(([label, value]) => React.createElement(
                            'tr',
                            { key: label },
                            React.createElement('td', { className: 'py-1 font-medium' }, label + ':'),
                            React.createElement('td', null, value)
                        ))
                    )
                )
            )
        )
    );

    return React.createElement(
        'div',
        { className: 'modal-overlay' },
        React.createElement(
            'div',
            { className: 'modal-content' },
            React.createElement(
                'div',
                { className: 'modal-header' },
                React.createElement('h2', { className: 'text-xl font-bold' }, 'Log Details'),
                React.createElement(
                    'button',
                    { onClick: onClose, className: 'close-button' },
                    '×'
                )
            ),
            React.createElement(
                'div',
                { className: 'modal-body' },
                renderOverviewTab()
            )
        )
    );
};

export default LogDetail;
