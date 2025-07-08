# TSMS Feature Roadmap

### Dashboard Visualization - PHASE 2

-   [ ] Terminal Enrollment History Chart
-   [ ] Fixed chart data generation and display
-   [ ] Implemented provider performance metrics
-   [ ] Added chart display in provider details
-   [ ] Fixed JSON encoding for chart data

### Provider Module Enhancements - PHASE 2

-   [ ] Provider details view
-   [ ] Terminal metrics display
-   [ ] Performance statistics
-   [ ] Real-time data updates
-   [ ] Error handling improvements

### Provider Dashboard Improvements - PHASE 2

-   [ ] Fixed Terminal Enrollment History chart display
-   [ ] Implemented proper data structure for chart metrics
-   [ ] Added real-time data updates
-   [ ] Improved error handling and logging

### Transaction Processing

-   [ ] Completed transaction logs implementation
-   [ ] Added export functionality
-   [ ] Implemented retry mechanism
-   [ ] Fixed routing issues for transaction views

### Dashboard Structure

-   [ ] Basic layout implementation
-   [ ] Tab navigation system
-   [ ] Component routing setup

### Terminal Tokens Module

-   [ ] Database schema implementation
-   [ ] Basic CRUD operations
-   [ ] UI components for token management
-   [ ] Token status handling (Active/Expired/Revoked)
-   [ ] Filter implementation by terminal ID and status

### Circuit Breaker Implementation

-   [ ] Core Implementation

    -   [ ] Middleware setup and registration
    -   [ ] State management (CLOSED, OPEN, HALF-OPEN)
    -   [ ] Multi-tenant support
    -   [ ] Failure counting with Redis
    -   [ ] Configurable thresholds and cooldown
    -   [ ] Error handling and logging

-   [ ] Integration & Configuration

    -   [ ] Laravel Horizon setup
    -   [ ] Redis metrics integration
    -   [ ] Queue configuration
    -   [ ] Environment variables setup

-   [ ] Testing & Verification

    -   [ ] Test endpoint with simulated failures
    -   [ ] State transition verification
        -   [ ] CLOSED to OPEN after failures
        -   [ ] OPEN to HALF-OPEN after cooldown
        -   [ ] HALF-OPEN to CLOSED on success
    -   [ ] Multi-tenant isolation testing
    -   [ ] Redis metrics verification
    -   [ ] Automatic recovery testing

-   [ ] Documentation

    -   [ ] Implementation guide
    -   [ ] Development procedures

### Authentication & Authorization

-   [ ] Core Implementation

    -   [ ] Sanctum token-based authentication
    -   [ ] Role-based access control with Spatie Permissions
    -   [ ] Secure login/logout functionality
    -   [ ] Protected route middleware
    -   [ ] Test coverage for authentication flows

-   [ ] Frontend Integration
    -   [ ] Authentication context setup
    -   [ ] Protected route components
    -   [ ] Login interface
    -   [ ] Token management
    -   [ ] User session handling

### Database Migrations

-   [ ] Terminal tokens table creation
-   [ ] Circuit breaker table setup
-   [ ] Proper foreign key relationships
-   [ ] Timestamp fields implementation

### 🔵 Dashboard Visualization Improvements PHASE 2

-   [ ] Terminal Enrollment History Chart
    -   [ ] Fixed chart data generation and display
    -   [ ] Implemented multi-series visualization (Total, Active, New Enrollments)
    -   [ ] Added proper data formatting and scaling
    -   [ ] Fixed JSON encoding issues in chart data
    -   [ ] Improved chart layout and responsiveness

### Transaction Data Model Enhancements

-   [ ] Added support for line-numbered format parsing
-   [ ] Implemented dynamic payload format detection
-   [ ] Enhanced transaction validation service
-   [ ] Added text format parsing capabilities

### Transaction Parser Implementation

-   [ ] Line-numbered format support
-   [ ] Multiple format detection (JSON, line-numbered, key-value)
-   [ ] Field normalization improvements
-   [ ] Enhanced validation logic

### Advanced Filtering Implementation

-   [ ] Add custom date range selector
-   [ ] Implement multiple format export
-   [ ] Add provider-specific filters
-   [ ] Real-time filter updates

### Dashboard Visualization

-   [ ] Provider performance metrics
-   [ ] Advanced filtering options
-   [ ] Custom date range selections
-   [ ] Export functionality for charts

### Transaction Module Enhancements

-   [ ] Bulk retry operations
-   [ ] Advanced search capabilities
-   [ ] Custom reporting tools
-   [ ] Real-time notifications

### Parser Module Enhancements

-   [ ] Add support for additional POS formats
-   [ ] Improve error handling for malformed inputs
-   [ ] Enhance validation feedback
-   [ ] Add format-specific validation rules

### 🟢 Retry History

-   [ ] Database schema design
-   [ ] API endpoint implementation
-   [ ] UI components development
-   [ ] Retry analytics
-   [ ] Admin interface to view retry attempts via Integration Logs
-   [ ] Filters by terminal, error type, retry count
-   [ ] Circuit breaker integration for manual retry attempts
-   [ ] Detailed view for individual retry history
-   [ ] Exponential backoff retry mechanism
-   [ ] Configurable retry limits and delays

### 🟢 Audit and Admin Log Viewer

-   [ ] Basic UI implementation
    -   Modern card-based design
    -   Responsive layout
    -   Tabbed interface for different log types
    -   Stats cards with real-time metrics
-   [ ] Log Management Features
    -   Centralized log viewing system
    -   Audit trail tracking
    -   Webhook logging
    -   System event logging
-   [ ] Advanced UI Components
    -   Live updates with toggle switch
    -   Advanced filtering system
    -   Context modal for detailed views
    -   Export functionality (CSV/PDF)
-   [ ] Visual Enhancements
    -   Status badges with contextual colors
    -   Progress indicators
    -   Hover effects on cards
    -   Consistent typography
-   [ ] Data Organization
    -   Table view with sortable columns
    -   Pagination support
    -   Search functionality
    -   Filter persistence
-   [ ] Integration Features
    -   WebSocket support for live updates
    -   REST API endpoints for data retrieval
    -   Export service implementation
    -   Context viewing capability

### 🟢 Module 2: POS Transaction Processing and Error Handling

-   [ ] Transaction Ingestion API (2.1.3.1)

    -   [ ] `/v1/transactions` endpoint implementation
    -   [ ] JWT authentication integration
    -   [ ] Payload validation
    -   [ ] Database storage in transactions table
    -   [ ] Idempotency handling to prevent duplicates

-   [ ] POS Text Format Parser (2.1.3.2)

    -   [ ] Text format parsing in TransactionValidationService
    -   [ ] Support for multiple text formats (KEY: VALUE, KEY=VALUE, KEY VALUE)
    -   [ ] Field normalization and mapping
    -   [ ] TransformTextFormat middleware
    -   [ ] Middleware registration in request pipeline

-   [ ] Job Queues and Processing Logic (2.1.3.3)

    -   [ ] Transaction processing via queued jobs
    -   [ ] Laravel Horizon configuration
    -   [ ] Redis-based queue for reliability
    -   [ ] Asynchronous processing
    -   [ ] ProcessTransactionJob implementation

-   [ ] Error Handling and Retry Mechanism (2.1.3.4)
    -   [ ] Circuit breaker pattern implementation
    -   [ ] Exponential backoff retry strategy
    -   [ ] Retry attempt tracking and logging
    -   [ ] Retry history admin interface
    -   [ ] Manual retry capabilities
    -   [ ] Retry analytics and monitoring

### 🟢 Transaction Processing Pipeline

-   [ ] Comprehensive Validation Implementation

    -   [ ] Store Information - FOR PHASE 2
        -   [ ] Added stores table and model
        -   [ ] Implemented store validation service
        -   [ ] Store validation integration
        -   [ ] Operating hours validation
    -   [ ] Amount Validations
        -   [ ] Zero/negative amount checks
        -   [ ] VAT calculation (12%) verification
        -   [ ] Net vs gross sales reconciliation
        -   [ ] Service charge calculations
        -   [ ] Amount range validations
    -   [ ] Discount Validations
        -   [ ] Discount calculation accuracy
        -   [ ] Maximum discount thresholds
        -   [ ] Discount authorization checks
        -   [ ] Promo code validation
    -   [ ] Transaction Integrity
        -   [ ] Duplicate transaction prevention
        -   [ ] Transaction sequence validation
        -   [ ] Timestamp validation
        -   [ ] Terminal authorization status
        -   [ ] Transaction ID format validation
    -   [ ] Business Rules
        -   [ ] Operating hours compliance - PHASE 2
        -   [ ] Transaction limits
        -   [ ] Service charge rules
        -   [ ] Tax exemption validation

-   [ ] Processing Pipeline

    -   [ ] Pre-processing Validation
        -   [ ] JSON schema validation
        -   [ ] Required field checks
        -   [ ] Data type validation
    -   [ ] Main Processing
        -   [ ] Asynchronous job queuing
        -   [ ] Status tracking
        -   [ ] Error handling
    -   [ ] Post-processing
        -   [ ] Response formatting
        -   [ ] Notification dispatch
        -   [ ] Log generation

-   [ ] Error Handling

    -   [ ] Validation error categorization
    -   [ ] Detailed error messages
    -   [ ] Error logging and tracking
    -   [ ] Retry strategy for recoverable errors

-   [ ] Monitoring & Reporting
    -   [ ] Validation statistics
    -   [ ] Error rate monitoring
    -   [ ] Performance metrics
    -   [ ] Audit trail generation

### Transaction Testing Tools and Diagnostics

-   [ ] Test Transaction UI Implementation

    -   [ ] Form interface for creating test transactions
    -   [ ] Field validation and error handling
    -   [ ] Transaction preview and confirmation
    -   [ ] Automatic field value calculation
    -   [ ] Valid/Invalid template buttons for fast testing

-   [ ] Transaction Diagnostics

    -   [ ] Recent test transactions display
    -   [ ] Transaction detail view
    -   [ ] Error message formatting and display
    -   [ ] Processing history tracking
    -   [ ] Manual retry capability
    -   [ ] Visual status indicators

-   [ ] Integration with Processing Pipeline
    -   [ ] Real transaction processing via standard flow
    -   [ ] Test-specific transaction flagging
    -   [ ] API endpoints for test transaction data
    -   [ ] Processing result visualization

### Test Data Infrastructure

-   [ ] Test Data Seeds

    -   [ ] RetryTransactionSeeder implementation
    -   [ ] Various transaction statuses (COMPLETED, FAILED, QUEUED, PROCESSING)
    -   [ ] Realistic financial data with proper calculations
    -   [ ] Terminal and tenant auto-creation if needed

-   [ ] Data Generation APIs

    -   [ ] Emergency data API for quick testing
    -   [ ] Force-seed API for controlled test data creation
    -   [ ] Recent test transaction API for UI display
    -   [ ] Transaction diagnostics APIs

-   [ ] Interactive Testing Tools
    -   [ ] Test transaction creation form
    -   [ ] Real-time transaction status tracking
    -   [ ] Recent transactions list
    -   [ ] One-click retry functionality
    -   [ ] Detailed error viewing

### 🟢 Logging System Implementation

-   [ ] Core Logging Infrastructure

    -   [ ] System logs implementation
    -   [ ] Audit trail logging
    -   [ ] Webhook logs integration
    -   [ ] Log types categorization
    -   [ ] Database schema optimization

-   [ ] Modern UI Implementation

    -   [ ] Dashboard layout with stats cards
    -   [ ] Tabbed interface for different log types
    -   [ ] Real-time updates with toggle
    -   [ ] Advanced filtering system
    -   [ ] Responsive design across devices

-   [ ] Log Features

    -   [ ] Live log updates
    -   [ ] Advanced search and filtering
    -   [ ] Export functionality (CSV/PDF)
    -   [ ] Context modal for detailed views
    -   [ ] Status badges with contextual colors

-   [ ] Log Management
    -   [ ] Centralized logging system
    -   [ ] Log categorization
    -   [ ] Error tracking
    -   [ ] Performance monitoring
    -   [ ] Audit trail history

### Notification System Implementation (MVP-010)

-   [ ] Core Notification Infrastructure

    -   [ ] Database schema design for notifications
    -   [ ] Notification model creation
    -   [ ] Notification service implementation
    -   [ ] Channel management (Email, SMS, In-app, WebSocket)
    -   [ ] Template system for notification content

-   [ ] User Notification Preferences

    -   [ ] User preference management
    -   [ ] Channel subscription options
    -   [ ] Notification frequency controls
    -   [ ] Critical vs. non-critical categorization

-   [ ] In-App Notification Center

    -   [ ] Notification listing UI
    -   [ ] Read/unread status tracking
    -   [ ] Notification actions integration
    -   [ ] Real-time updates via WebSockets
    -   [ ] Notification counter and indicators

-   [ ] Triggered Notifications

    -   [ ] Transaction status change notifications
    -   [ ] Validation error alerts
    -   [ ] System health notifications
    -   [ ] Security event notifications
    -   [ ] Tenant activity notifications
    -   [ ] Circuit breaker state change alerts

-   [ ] Notification Administration
    -   [ ] Notification broadcasting controls
    -   [ ] Batch notification management
    -   [ ] Notification analytics
    -   [ ] Delivery status tracking
    -   [ ] Rate limiting and throttling controls

## 🔮 FUTURE ENHANCEMENT

### POS Terminal Registration System

-   [ ] API Design & Documentation

    -   [ ] Endpoint implementation: POST /api/v1/terminals/register
    -   [ ] Payload schema design (tenant_code, terminal_uid, provider_jwt, metadata)
    -   [ ] Response schema implementation (terminal_id, jwt_token, enrolled_at)
    -   [ ] Error codes standardization (400, 401, 409, 500)
    -   [ ] API documentation and developer guide

-   [ ] Terminal Import UI

    -   [ ] File upload form with schema validation
    -   [ ] Preview grid with validation status
    -   [ ] Error indication for invalid entries
    -   [ ] Batch token generation
    -   [ ] Commit functionality with validation

-   [ ] Provider Onboarding Path

    -   [ ] Tier 1 Provider Resources (Tech-Savvy)
        -   [ ] API key management system
        -   [ ] JWT handshake documentation
        -   [ ] Postman collection development
        -   [ ] Sample script creation
    -   [ ] Tier 2 Provider Resources (Less Technical)
        -   [ ] JSON/CSV template design
        -   [ ] Step-by-step import guide
        -   [ ] Support system for transition assistance

-   [ ] Security Implementation

    -   [ ] API security middleware
    -   [ ] Provider JWT verification (pos_providers.public_key)
    -   [ ] Provider accreditation status checks
    -   [ ] Rate limiting implementation
    -   [ ] API call logging
    -   [ ] Role-based access for import UI (PROVIDER_ADMIN, TMS_SUPERADMIN)
    -   [ ] Schema and tenant integrity validation
    -   [ ] Audit logging for imports

-   [ ] Monitoring & Optimization
    -   [ ] Enrollment method tracking (API vs. manual)
    -   [ ] Import system retirement planning
    -   [ ] Alert system for failed validations
    -   [ ] Usage analytics dashboard
    -   [ ] Performance optimization

### Event-Driven Architecture Implementation

-   [ ] Schema Registry System

    -   [ ] Centralized schema management
    -   [ ] Schema caching mechanism
    -   [ ] Performance optimization
    -   [ ] SDK development for schema handling
    -   [ ] Schema version control

-   [ ] Event Gateway Enhancement

    -   [ ] External producer authentication
    -   [ ] Internal producer routing
    -   [ ] Gateway security improvements
    -   [ ] Rate limiting implementation
    -   [ ] Authorization management

-   [ ] Event Processing Module

    -   [ ] Event validation service
    -   [ ] Schema contract verification
    -   [ ] Event queue management
    -   [ ] Processing pipeline optimization
    -   [ ] Real-time event tracking

-   [ ] Event Storage System

    -   [ ] Temporary event store
    -   [ ] Queue unavailability handling
    -   [ ] Event persistence management
    -   [ ] Storage optimization strategies
    -   [ ] Data recovery mechanisms

-   [ ] Consumer Management

    -   [ ] Multiple consumer support
    -   [ ] Consumer-specific queues
    -   [ ] Load balancing implementation
    -   [ ] Consumer health monitoring
    -   [ ] Scaling capabilities

-   [ ] Producer Infrastructure
    -   [ ] External producer management
    -   [ ] Internal producer framework
    -   [ ] Producer authentication
    -   [ ] Event submission validation
    -   [ ] Producer monitoring tools

## 📅 Last Updated

-   Date: 2025-05-30
-   Version: 1.3.0
-   Latest Changes:
    -   Added Notification System Implementation plan (MVP-010)
    -   Completed Logging System Implementation
    -   Enhanced logging UI with modern design
    -   Added real-time log updates
    -   Implemented advanced filtering
    -   Integrated webhook logging system
    -   Fixed retry count increment when using Retry button
    -   Fixed validation status display for PENDING state
    -   Fixed ProcessTransactionJob to properly handle validation states
    -   Improved error handling for failed jobs
    -   Added proper job status tracking
    -   Fixed job attempts counting
    -   Improved validation status consistency (VALID/ERROR/PENDING)
