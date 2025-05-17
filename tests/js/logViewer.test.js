/**
 * @jest-environment jsdom
 */

describe("Log Viewer", () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div id="logs-table"></div>
            <div id="pagination"></div>
            <form id="filter-form">
                <select id="log_type" name="log_type">
                    <option value="all">All Logs</option>
                </select>
                <input type="text" id="search" name="search">
                <button type="submit">Apply Filters</button>
                <button type="button" id="reset-filters">Reset</button>
            </form>
            <button id="toggle-advanced-search">Advanced Search</button>
            <div id="advanced-search-panel" style="display: none;"></div>
        `;

        // Mock fetch API
        global.fetch = jest.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        data: [
                            {
                                id: 1,
                                log_type: "transaction",
                                severity: "error",
                                message: "Test error message",
                                transaction_id: "TX-123456",
                                created_at: "2025-05-20T10:30:00Z",
                                posTerminal: { terminal_uid: "TERM-001" },
                            },
                        ],
                        meta: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 10,
                            total: 1,
                        },
                        stats: {
                            total_logs: 10,
                            error_count: 3,
                            warning_count: 2,
                            info_count: 5,
                        },
                    }),
            })
        );

        // Mock the Bootstrap modal
        global.bootstrap = {
            Modal: jest.fn().mockImplementation(() => ({
                show: jest.fn(),
                hide: jest.fn(),
            })),
        };
    });

    test("toggle advanced search panel", () => {
        // Include your actual JS file here
        require("../../resources/js/log-viewer.js");

        const toggleBtn = document.getElementById("toggle-advanced-search");
        const panel = document.getElementById("advanced-search-panel");

        // Initially hidden
        expect(panel.style.display).toBe("none");

        // Click to show
        toggleBtn.click();
        expect(panel.style.display).toBe("block");

        // Click to hide
        toggleBtn.click();
        expect(panel.style.display).toBe("none");
    });

    test("applies filters correctly", () => {
        // Include your actual JS file here
        require("../../resources/js/log-viewer.js");

        const form = document.getElementById("filter-form");
        form.dispatchEvent(new Event("submit"));

        // Check that fetch was called with the right URL
        expect(global.fetch).toHaveBeenCalled();
        expect(global.fetch.mock.calls[0][0]).toContain(
            "/api/web/dashboard/logs"
        );
    });

    // Add more tests for other JavaScript functionality
});
