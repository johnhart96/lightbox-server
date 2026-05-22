# Lightbox-server

Lightbox-server is a web-managed network infrastructure suite explicitly tailored for entertainment and lighting production networks. It consolidates essential network services into a single, lightweight appliance designed to run flawlessly on a Raspberry Pi or any Debian/Ubuntu-based machine.

By replacing manual configuration files with an intuitive web interface, Lightbox-server makes it easy to deploy stable, isolated production networks on the fly.

## 📦 Core Network Services

*   **DHCP Server:** Dynamic IP allocation supporting both IPv4 and IPv6 topologies.
*   **DNS Server:** Local name resolution featuring **automatic DNS registration for active DHCP leases**, alongside support for custom static entries.
*   **NTP Server:** Accurate, localized time synchronization across consoles, media servers, and network nodes.
*   **Samba File Sharing:** Simple network-attached storage for sharing project files, show files, and assets.

## ✨ Web UI Features

*   **Interface Management:** Configure and modify physical network interfaces directly from your browser.
*   **Resource Monitoring:** Real-time visibility into CPU, memory, temperature, and storage utilization.
*   **Log Aggregation:** View live log outputs from all running underlying services inside the UI.
*   **Data Portability:** Seamless configuration backup and restore—export or import the application database (`lightbox.db`) with one click.
*   **Automated Device Discovery (ANSI E1.17):** Listen for Architecture for Control Networks (ACN) traffic to automatically discover production hardware and register matching local DNS entries.
*   **Unified Devices Tab:** An inventory dashboard tracking all manually assigned and DHCP-leased devices with real-time online/offline status tracking.
*   **Access Control:** Robust user authentication layers for both the management Web UI and underlying Samba fileshares.

---

## 🗺️ Roadmap & In-Progress

### ⚡ Active Development
*   **Service Toggles:** A quick-access settings dialog to easily spin services up or down (utilizing `docker start / stop` backends).
### 🛠️ Upcoming Features

*   **TFTP Deployment:** Dedicate a specific fileshare directory to act as a TFTP root, streamlining firmware updates for lighting nodes and fixtures.
*   **Local PKI:** Integrated Certificate Authority (CA) to easily deploy and sign local SSL/TLS certificates.
*   **Advanced Networking:** Reverse DNS zone management, centralized Syslog collection, and native Wi-Fi infrastructure support.


## 🐛 Known Bugs

*   *No critical bugs reported. Found an issue? Please open an Issue.*

## 💻 Target Environments

*   **Primary:** Raspberry Pi (Raspberry Pi OS / Debian Buster or Bullseye+)
*   **Alternative:** Any bare-metal or virtualized machine running standard **Debian** or **Ubuntu**.