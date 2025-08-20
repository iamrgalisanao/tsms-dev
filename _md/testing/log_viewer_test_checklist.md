# Log Viewer Testing Checklist

## 1. Basic Functionality

-   [ ] Navigate to `/dashboard/logs`
    -   [ ] Page loads without errors
    -   [ ] Sidebar navigation item is highlighted
    -   [ ] Statistics panel shows correct data
    -   [ ] Log table displays properly
    -   [ ] Pagination controls work

## 2. Filtering & Search

-   [ ] Filter by Log Type

    -   [ ] 'All Logs' option works
    -   [ ] 'Transaction Logs' filters correctly
    -   [ ] 'Authentication Logs' filters correctly
    -   [ ] 'Error Logs' filters correctly
    -   [ ] 'Security Logs' filters correctly

-   [ ] Filter by Terminal

    -   [ ] Terminal dropdown populates with correct terminals
    -   [ ] Selecting a terminal filters logs appropriately

-   [ ] Filter by Severity

    -   [ ] 'All Severities' option works
    -   [ ] 'Error' shows only error logs
    -   [ ] 'Warning' shows only warning logs
    -   [ ] 'Info' shows only info logs

-   [ ] Filter by Date Range

    -   [ ] Date From works independently
    -   [ ] Date To works independently
    -   [ ] Both Date From and Date To work together
    -   [ ] Date validation works properly

-   [ ] Search Functionality

    -   [ ] Basic search finds matching logs
    -   [ ] Search works with other filters applied

-   [ ] Advanced Search

    -   [ ] Toggle button shows/hides advanced search panel
    -   [ ] Search Field dropdown works (Message, Transaction ID, Context, All Fields)
    -   [ ] Operator dropdown works (Contains, Equals, Starts With, Ends With, Regex)
    -   [ ] Search Value works with different operators

-   [ ] Reset Filter Button
    -   [ ] Successfully clears all filters
    -   [ ] Returns to unfiltered view

## 3. Export Functionality

-   [ ] CSV Export

    -   [ ] Export dropdown menu opens
    -   [ ] Selecting CSV initiates download
    -   [ ] CSV file contains correct headers
    -   [ ] CSV file contains correct data
    -   [ ] Filters are respected in export

-   [ ] PDF Export
    -   [ ] Selecting PDF initiates download
    -   [ ] PDF is formatted correctly
    -   [ ] PDF includes header information
    -   [ ] PDF includes correct log data
    -   [ ] PDF respects applied filters

## 4. Log Detail View

-   [ ] View Details
    -   [ ] Clicking 'View' button opens log details
    -   [ ] All log information is displayed correctly
    -   [ ] JSON data is properly formatted
    -   [ ] Navigate back to log list works

## 5. Live Updates

-   [ ] Live Updates Toggle
    -   [ ] Toggle switch can be turned on/off
    -   [ ] When on, new logs appear at the top automatically
    -   [ ] Highlight effect works on new logs
    -   [ ] Statistics update automatically

## 6. Role-Based Access

-   [ ] Admin Access

    -   [ ] Admin can see User filter dropdown
    -   [ ] Admin can filter by users
    -   [ ] Admin can see all logs across users

-   [ ] Regular User Access
    -   [ ] Regular user cannot see User filter
    -   [ ] Regular user sees only appropriate logs

## 7. Edge Cases

-   [ ] Empty State

    -   [ ] No logs message displays when no results
    -   [ ] Empty state is properly handled for exports

-   [ ] Error Handling
    -   [ ] API errors are properly displayed
    -   [ ] Failed exports show user-friendly error
    -   [ ] Network issues are handled gracefully

## Notes & Issues

-   Record any bugs, UI issues, or performance problems here:
    -
