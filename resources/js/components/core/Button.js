/** @jsxRuntime classic */
/** @jsx React.createElement */
import React from 'react';

const Button = ({ children, onClick, variant = 'primary', disabled = false, type = 'button' }) => {
  const baseStyles = {
    padding: '8px 16px',
    borderRadius: '4px',
    cursor: 'pointer',
    border: 'none',
    fontSize: '14px',
    fontWeight: '500',
  };

  const variants = {
    primary: {
      backgroundColor: '#0066cc',
      color: 'white',
    },
    secondary: {
      backgroundColor: '#f0f0f0',
      color: '#333',
    },
    danger: {
      backgroundColor: '#dc2626',
      color: 'white',
    }
  };

  const styles = {
    ...baseStyles,
    ...variants[variant],
    opacity: disabled ? 0.6 : 1,
    cursor: disabled ? 'not-allowed' : 'pointer',
  };

  return React.createElement(
    'button',
    {
      onClick,
      disabled,
      type,
      style: styles
    },
    children
  );
};

export default Button;
