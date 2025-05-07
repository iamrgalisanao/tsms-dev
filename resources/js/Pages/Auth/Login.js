import React, { useState } from 'react';
import { useAuth } from '../../Contexts/AuthContext';
import { useNavigate } from 'react-router-dom';

const Login = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const { login } = useAuth();
    const navigate = useNavigate();

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setIsLoading(true);

        try {
            await login(email, password);
            navigate('/dashboard');
        } catch (err) {
            setError('Invalid credentials');
        } finally {
            setIsLoading(false);
        }
    };

    return React.createElement('div', 
        { className: 'min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8' },
        React.createElement('div', 
            { className: 'max-w-md w-full space-y-8' },
            [
                React.createElement('div', 
                    { key: 'header' },
                    [
                        React.createElement('h2',
                            { 
                                key: 'title',
                                className: 'mt-6 text-center text-3xl font-extrabold text-gray-900'
                            },
                            'Sign in to Dashboard'
                        ),
                    ]
                ),
                React.createElement('form',
                    {
                        key: 'form',
                        className: 'mt-8 space-y-6',
                        onSubmit: handleSubmit
                    },
                    [
                        React.createElement('div',
                            { 
                                key: 'inputs',
                                className: 'rounded-md shadow-sm -space-y-px'
                            },
                            [
                                React.createElement('div',
                                    { key: 'email-input' },
                                    React.createElement('input',
                                        {
                                            type: 'email',
                                            required: true,
                                            className: 'appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm',
                                            placeholder: 'Email address',
                                            value: email,
                                            onChange: (e) => setEmail(e.target.value)
                                        }
                                    )
                                ),
                                React.createElement('div',
                                    { key: 'password-input' },
                                    React.createElement('input',
                                        {
                                            type: 'password',
                                            required: true,
                                            className: 'appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm',
                                            placeholder: 'Password',
                                            value: password,
                                            onChange: (e) => setPassword(e.target.value)
                                        }
                                    )
                                ),
                            ]
                        ),
                        error && React.createElement('div',
                            {
                                key: 'error',
                                className: 'text-red-600 text-sm text-center'
                            },
                            error
                        ),
                        React.createElement('div',
                            { key: 'submit' },
                            React.createElement('button',
                                {
                                    type: 'submit',
                                    disabled: isLoading,
                                    className: `group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 ${isLoading ? 'opacity-75 cursor-not-allowed' : ''}`
                                },
                                isLoading ? 'Signing in...' : 'Sign in'
                            )
                        )
                    ]
                )
            ]
        )
    );
};

export default Login;
