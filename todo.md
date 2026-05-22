# LightBox

An all-in-one network services platform designed for entertainment and lighting production networks. LightBox simplifies local infrastructure by combining core network utilities, storage services, and device management into a single, web-managed application.

## 🚀 Core Features

*   **Service Management:** Centralized settings dashboard to quickly toggle infrastructure services on and off (manages underlying service containers).
*   **Storage & Deployment:** Built-in TFTP server mapped to a dedicated local fileshare for easy device provisioning and firmware updates.
*   **Network Configuration:** Easily set and manage your entertainment network IP and local subnets through the Web UI.
*   **Security & Authentication:** Comprehensive user authentication controlling access to both the Web UI and underlying fileshares.
*   **Local PKI:** Built-in Certificate Authority (CA) to issue and manage local TLS/SSL certificates.
*   **Data Portability:** Seamless configuration backup and restore (export/import `lightbox.db`) directly via the web interface.

## 🗺️ Roadmap & In-Progress

### Active Development
*   **Automated Device Discovery:** Native Architecture for Control Networks (ACN / ANSI E1.17) integration to automatically discover production hardware and register matching dynamic DNS entries.
*   **Devices Dashboard:** A unified inventory tab to monitor all manually added and DHCP-leased devices with real-time online/offline status pinging.

### Planned Infrastructure
*   **Reverse DNS:** Automated PTR record management for local IP zones.
*   **Centralized Logging:** Integrated Syslog server to aggregate logs from network switches, consoles, and nodes.
*   **Wireless Networking:** Native Wi-Fi support for untethered control and deployment.

## 🐛 Known Bugs

*   *No critical bugs reported. Found an issue? Please open a GitHub Issue.*

## 🛠️ Architecture & Tech Stack

*   **Database:** SQLite (`lightbox.db`)
*   **Service Architecture:** Docker-managed backend services