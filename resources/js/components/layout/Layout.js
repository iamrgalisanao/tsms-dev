const React = require('react');

const Layout = ({ children }) => {
  console.log('Layout rendering with children:', children);
  
  return React.createElement(
    'div',
    { 
      style: {
        padding: '20px',
        backgroundColor: '#f0f2f5',
        minHeight: '100vh'
      }
    },
    React.createElement(
      'h1',
      {
        style: {
          fontSize: '24px',
          fontWeight: 'bold',
          marginBottom: '20px',
          color: '#1a1a1a'
        }
      },
      'Transaction System Dashboard'
    ),
    children
  );
};

// Use multiple export patterns to ensure compatibility
module.exports = Layout;
module.exports.default = Layout;
exports.default = Layout;
