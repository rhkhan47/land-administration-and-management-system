# 🚀 Hyperledger Fabric Land Administration and Management System

This project implements a **Land Registry System using Hyperledger Fabric**, with:

* **Hyperledger Fabric Network** (Blockchain)
* **Node.js Backend API**
* **PHP Frontend**

The system allows users to interact with blockchain-based land records through a web interface.

Instructions are based on the project run guide. 

---

# 📦 Project Structure

```
project-root/
│
├── fabric-samples/
│   ├── test-network/        # Hyperledger Fabric test network
│   ├── land-chaincode/      # Smart contract (chaincode)
│   ├── fabric-app/          # Node.js backend API
│   └── frontend/            # PHP frontend
```

---

# 🧰 Prerequisites

Make sure the following tools are installed:

* Docker
* Docker Compose
* Node.js (v16+ recommended)
* npm
* PHP (or XAMPP)
* Git
* Hyperledger Fabric Samples & Binaries

---

# 1️⃣ Start the Hyperledger Fabric Network

Navigate to the **test-network** directory:

```bash
cd fabric-samples/test-network
```

### Stop any existing network

```bash
./network.sh down
```

### Start the network and create a channel

```bash
./network.sh up createChannel -c landchannel -ca -s couchdb
```

This command:

* Starts Fabric peers and orderer
* Creates a channel named **landchannel**
* Enables **Certificate Authorities**
* Uses **CouchDB** as the state database

---

# 2️⃣ Deploy the Chaincode

Deploy the land registry smart contract:

```bash
./network.sh deployCC \
  -ccn landcc \
  -ccp ../land-chaincode \
  -ccl javascript \
  -c landchannel
```

Where:

| Parameter | Meaning            |
| --------- | ------------------ |
| `-ccn`    | Chaincode name     |
| `-ccp`    | Chaincode path     |
| `-ccl`    | Chaincode language |
| `-c`      | Channel name       |

---

# 3️⃣ Start the Backend API

Open a **new terminal** and navigate to the backend folder:

```bash
cd fabric-samples/fabric-app
```

Install dependencies (first time only):

```bash
npm install
```

Start the server:

```bash
npm start
```

The backend will run on:

```
http://localhost:3000
```

### Health Check (Optional)

```bash
curl http://localhost:3000/health
```

Expected response:

```json
{"ok": true}
```

---

# 4️⃣ Run the Frontend

Navigate to the frontend folder:

```bash
cd fabric-samples/frontend
```

Start a PHP development server:

```bash
php -S localhost:8080
```

The frontend will be available at:

```
http://localhost:8080
```

---

# ⚙️ Required PHP Extensions

If the frontend communicates with the Node.js API using **cURL**, ensure the following PHP extensions are enabled.

Open your **php.ini** file and enable:

```
extension=curl
extension=openssl
```

Restart the PHP server after making changes.

---

# 🌐 System Architecture

```
User (Browser)
       │
       ▼
PHP Frontend
       │
       ▼
Node.js Backend API
       │
       ▼
Hyperledger Fabric Network
       │
       ▼
Smart Contract (Chaincode)
       │
       ▼
CouchDB Ledger
```

---

# ▶️ Quick Start Summary

```bash
# Start Fabric Network
cd fabric-samples/test-network
./network.sh down
./network.sh up createChannel -c landchannel -ca -s couchdb

# Deploy Chaincode
./network.sh deployCC -ccn landcc -ccp ../land-chaincode -ccl javascript -c landchannel

# Start Backend
cd ../fabric-app
npm install
npm start

# Start Frontend
cd ../frontend
php -S localhost:8080
```

---

# 📌 Access the Application

| Service      | URL                          |
| ------------ | ---------------------------- |
| Frontend     | http://localhost:8080        |
| Backend API  | http://localhost:3000        |
| Health Check | http://localhost:3000/health |

---

# 🛠 Tech Stack

* **Hyperledger Fabric**
* **Node.js / Express**
* **PHP**
* **Docker**
* **CouchDB**

---

# 📄 License

This project is for **educational and research purposes**.

