import React from 'react';
import { useAuth } from '../../Contexts/AuthContext';

const Navbar = () => {
    const { user, logout } = useAuth();

    const handleLogout = async () => {
        await logout();
        window.location.href = '/login';
    };

    return React.createElement('nav',
        {
            className: 'bg-white shadow-sm'
        },
        React.createElement('div',
            {
                className: 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8'
            },
            React.createElement('div',
                {
                    className: 'flex justify-between h-16'
                },
                [
                    React.createElement('div',
                        {
                            key: 'left',
                            className: 'flex items-center'
                        },
                        React.createElement('h1',
                            {
                                className: 'text-lg font-semibold text-gray-800'
                            },
                            'Circuit Breaker Dashboard'
                        )
                    ),
                    React.createElement('div',
                        {
                            key: 'right',
                            className: 'flex items-center space-x-4'
                        },
                        [
                            React.createElement('span',
                                {
                                    key: 'user-email',
                                    className: 'text-sm text-gray-600'
                                },
                                user?.email
                            ),
                            React.createElement('button',
                                {
                                    key: 'logout',
                                    onClick: handleLogout,
                                    className: 'text-sm text-red-600 hover:text-red-800'
                                },
                                'Logout'
                            )
                        ]
                    )
                ]
            )
        )
    );
};

export default Navbar;
