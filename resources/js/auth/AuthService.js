class AuthService {
    constructor() {
        this.token = localStorage.getItem("auth_token");
        this.user = JSON.parse(localStorage.getItem("auth_user") || "{}");
    }

    isAuthenticated() {
        return !!this.token;
    }

    setToken(token, user) {
        this.token = token;
        this.user = user;
        localStorage.setItem("auth_token", token);
        localStorage.setItem("auth_user", JSON.stringify(user));
    }

    clearToken() {
        this.token = null;
        this.user = {};
        localStorage.removeItem("auth_token");
        localStorage.removeItem("auth_user");
    }

    getAuthHeaders() {
        return {
            Authorization: `Bearer ${this.token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
        };
    }

    async login(email, password) {
        try {
            const response = await fetch("/api/auth/login", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },
                body: JSON.stringify({ email, password }),
            });

            const data = await response.json();

            if (response.ok) {
                this.setToken(data.token, data.user);
                return { success: true, user: data.user };
            } else {
                return {
                    success: false,
                    message: data.message || "Login failed",
                };
            }
        } catch (error) {
            return { success: false, message: "Network error" };
        }
    }

    async logout() {
        try {
            if (this.token) {
                await fetch("/api/auth/logout", {
                    method: "POST",
                    headers: this.getAuthHeaders(),
                });
            }
        } catch (error) {
            console.error("Logout error:", error);
        } finally {
            this.clearToken();
            window.location.href = "/login";
        }
    }
}

export default new AuthService();
