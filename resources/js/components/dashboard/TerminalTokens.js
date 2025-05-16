import React, { useState, useEffect } from "react";
import axios from "axios";
import { format, formatDistance } from "date-fns";

const TerminalTokens = () => {
    const [tokens, setTokens] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchTokens = async () => {
            try {
                // Update the API endpoint to match the route defined in api.php
                const response = await axios.get("/api/v1/terminal-tokens");
                setTokens(response.data);
            } catch (err) {
                setError("Failed to load terminal tokens");
                console.error("Error fetching tokens:", err);
            } finally {
                setLoading(false);
            }
        };

        fetchTokens();
    }, []);

    // Update other API endpoints as well
    const handleRevoke = async (tokenId) => {
        try {
            await axios.post(`/api/v1/terminal-tokens/${tokenId}/revoke`);
            const updatedTokens = await axios.get("/api/v1/terminal-tokens");
            setTokens(updatedTokens.data);
        } catch (err) {
            setError("Failed to revoke token");
            console.error("Error revoking token:", err);
        }
    };

    const handleRegenerate = async (terminalId) => {
        try {
            await axios.post(
                `/api/v1/terminal-tokens/${terminalId}/regenerate`
            );
            const updatedTokens = await axios.get("/api/v1/terminal-tokens");
            setTokens(updatedTokens.data);
        } catch (err) {
            setError("Failed to regenerate token");
            console.error("Error regenerating token:", err);
        }
    };

    const copyToken = async (token) => {
        try {
            await navigator.clipboard.writeText(token.access_token);
            // Optional: Add a temporary success message
            alert("Token copied to clipboard");
        } catch (err) {
            console.error("Failed to copy token:", err);
        }
    };

    if (loading) {
        return <div className="p-4">Loading terminal tokens...</div>;
    }

    if (error) {
        return <div className="p-4 text-red-600">{error}</div>;
    }

    return (
        <div className="p-4">
            <h2 className="text-lg font-semibold mb-4">
                Terminal Tokens (POS Authentication)
            </h2>
            {tokens && tokens.length > 0 ? (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-6 py-3 text-left">
                                    Terminal ID
                                </th>
                                <th className="px-6 py-3 text-left">Token</th>
                                <th className="px-6 py-3 text-left">Status</th>
                                <th className="px-6 py-3 text-left">Expires</th>
                                <th className="px-6 py-3 text-left">
                                    Last Used
                                </th>
                                <th className="px-6 py-3 text-left">Guard</th>
                                <th className="px-6 py-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {tokens.map((token) => (
                                <tr key={token.id}>
                                    <td className="px-6 py-4">
                                        {token.terminal?.terminal_uid}
                                    </td>
                                    <td className="px-6 py-4">
                                        {token.access_token.substring(0, 16)}...
                                    </td>
                                    <td className="px-6 py-4">
                                        <span
                                            className={`px-2 py-1 rounded ${
                                                token.is_revoked
                                                    ? "bg-red-100 text-red-800"
                                                    : "bg-green-100 text-green-800"
                                            }`}
                                        >
                                            {token.is_revoked
                                                ? "Revoked"
                                                : "Active"}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4">
                                        {token.expires_at
                                            ? format(
                                                  new Date(token.expires_at),
                                                  "PPP"
                                              )
                                            : "N/A"}
                                    </td>
                                    <td className="px-6 py-4">
                                        {token.last_used_at
                                            ? formatDistance(
                                                  new Date(token.last_used_at),
                                                  new Date(),
                                                  { addSuffix: true }
                                              )
                                            : "Never"}
                                    </td>
                                    <td className="px-6 py-4">auth:pos_api</td>
                                    <td className="px-6 py-4">
                                        <div className="space-x-2 flex items-center">
                                            <button
                                                onClick={() => copyToken(token)}
                                                className="text-gray-600 hover:text-gray-800"
                                                title="Copy full token"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    className="h-5 w-5"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" />
                                                    <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" />
                                                </svg>
                                            </button>
                                            {!token.is_revoked && (
                                                <button
                                                    onClick={() =>
                                                        handleRevoke(token.id)
                                                    }
                                                    className="text-red-600 hover:text-red-800"
                                                >
                                                    Revoke
                                                </button>
                                            )}
                                            <button
                                                onClick={() =>
                                                    handleRegenerate(
                                                        token.terminal_id
                                                    )
                                                }
                                                className="text-blue-600 hover:text-blue-800"
                                            >
                                                Regenerate
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="text-gray-500">
                    <p>No terminal tokens found</p>
                    <p className="text-sm mt-2">
                        Tokens are issued upon terminal registration via
                        /register-terminal endpoint
                    </p>
                </div>
            )}

            <div className="mt-4 p-4 bg-gray-50 rounded-lg text-sm">
                <p className="font-medium">🔐 Token Usage:</p>
                <code className="block mt-2 bg-gray-100 p-2 rounded">
                    Authorization: Bearer &lt;JWT_TOKEN&gt;
                </code>
            </div>
        </div>
    );
};

export default TerminalTokens;
