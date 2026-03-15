require('dotenv').config();
const path    = require('path');
const express = require('express');
const cors    = require('cors');
const morgan  = require('morgan');

const { importOrg1UserToWallet } = require('./identity');
const { connect, disconnect }    = require('./fabric');

const app = express();
app.use(cors());
app.use(express.json({ limit: '2mb' }));
app.use(morgan('dev'));

const PORT        = process.env.PORT || 3000;
const CHANNEL     = process.env.CHANNEL_NAME  || 'landchannel';
const CHAINCODE   = process.env.CHAINCODE_NAME|| 'landcc';
const ORG_MSP     = process.env.ORG_MSP       || 'Org1MSP';
const USER_LABEL  = process.env.USER_LABEL    || 'org1user';

const walletPath  = path.resolve(__dirname, '..', 'wallet');

async function ensureReady() {
  const wallet   = await importOrg1UserToWallet({ walletPath, userLabel: USER_LABEL, orgMsp: ORG_MSP });
  const contract = await connect({ wallet, identityLabel: USER_LABEL, channelName: CHANNEL, chaincodeName: CHAINCODE });
  return { wallet, contract };
}

app.get('/health', async (req, res) => {
  try {
    await ensureReady();
    res.json({ ok: true, channel: CHANNEL, chaincode: CHAINCODE });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// generic query
app.post('/api/query', async (req, res) => {
  try {
    const { fcn, args } = req.body || {};
    if (!fcn) return res.status(400).json({ error: 'Missing fcn' });
    const { contract } = await ensureReady();
    const resultBuf = await contract.evaluateTransaction(fcn, ...(args || []));
    let result = resultBuf.toString();
    try { result = JSON.parse(result); } catch {}
    res.json({ ok: true, fcn, args: args || [], result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message, stack: e.stack });
  }
});

// generic invoke
app.post('/api/invoke', async (req, res) => {
  try {
    const { fcn, args } = req.body || {};
    if (!fcn) return res.status(400).json({ error: 'Missing fcn' });
    const { contract } = await ensureReady();
    const tx   = contract.createTransaction(fcn);
    const resp = await tx.submit(...(args || []));
    const txId = tx.getTransactionId();
    let result = resp.toString();
    try { result = JSON.parse(result); } catch {}
    res.json({ ok: true, txId, fcn, args: args || [], result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message, stack: e.stack });
  }
});

// specific read user
app.get('/api/users/:id', async (req, res) => {
  try {
    const { id } = req.params;
    const { contract } = await ensureReady();
    const resultBuf = await contract.evaluateTransaction('readUser', id);
    const result = JSON.parse(resultBuf.toString());
    res.json({ ok: true, result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// pending users
app.get('/api/pendingUsers', async (req, res) => {
  try {
    const { contract } = await ensureReady();
    const resultBuf = await contract.evaluateTransaction('queryPendingUsers');
    const result = JSON.parse(resultBuf.toString());
    res.json({ ok: true, result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// pending land
app.get('/api/pendingLand', async (req, res) => {
  try {
    const { contract } = await ensureReady();
    const resultBuf = await contract.evaluateTransaction('queryPendingLand');
    const result = JSON.parse(resultBuf.toString());
    res.json({ ok: true, result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// available for mortgage
app.get('/api/availableMortgage', async (req, res) => {
  try {
    const { contract } = await ensureReady();
    const resultBuf = await contract.evaluateTransaction('queryAvailableForMortgage');
    const result = JSON.parse(resultBuf.toString());
    res.json({ ok: true, result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// available for lease
app.get('/api/availableLease', async (req, res) => {
  try {
    const { contract } = await ensureReady();
    const resultBuf = await contract.evaluateTransaction('queryAvailableForLease');
    const result = JSON.parse(resultBuf.toString());
    res.json({ ok: true, result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// cancel sale
app.post('/api/cancelSale', async (req, res) => {
  try {
    const { landId } = req.body;
    if (!landId) return res.status(400).json({ ok: false, error: 'landId required' });
    const { contract } = await ensureReady();
    const tx   = contract.createTransaction('cancelSale');
    const resp = await tx.submit(landId);
    const txId = tx.getTransactionId();
    const result = JSON.parse(resp.toString());
    res.json({ ok: true, txId, result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

process.on('SIGINT', async () => {
  await disconnect();
  process.exit(0);
});

app.listen(PORT, () => {
  console.log(`✅ Backend running on http://localhost:${PORT}`);
  console.log(`    Channel:  ${CHANNEL}`);
  console.log(`    Chaincode:${CHAINCODE}`);
});
