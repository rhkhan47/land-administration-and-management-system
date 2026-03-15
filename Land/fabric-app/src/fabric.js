const fs   = require('fs');
const path = require('path');
const { Gateway } = require('fabric-network');

let _gateway  = null;
let _contract = null;

function readPemOrThrow(p) {
  if (!fs.existsSync(p)) throw new Error(`TLS file not found: ${p}`);
  const pem = fs.readFileSync(p, 'utf8').trim();
  if (!pem.includes('-----BEGIN CERTIFICATE-----')) {
    throw new Error(`TLS file does not look like PEM: ${p}`);
  }
  return pem;
}

function buildCcp() {
  const peerTls = readPemOrThrow(path.resolve(
    __dirname, '..', '..',
    'test-network', 'organizations', 'peerOrganizations',
    'org1.example.com', 'peers', 'peer0.org1.example.com', 'tls', 'ca.crt'
  ));
  const ordererTls = readPemOrThrow(path.resolve(
    __dirname, '..', '..',
    'test-network', 'organizations', 'ordererOrganizations',
    'example.com', 'orderers', 'orderer.example.com', 'tls', 'ca.crt'
  ));

  return {
    name: 'basic-network',
    version: '1.0.0',
    client: { organization: 'Org1', connection: { timeout: { peer: { endorser: '300' }, orderer: '300' } } },
    organizations: { Org1: { mspid: 'Org1MSP', peers: ['peer0.org1.example.com'], certificateAuthorities: [] } },
    peers: {
      'peer0.org1.example.com': {
        url: 'grpcs://localhost:7051',
        tlsCACerts: { pem: peerTls },
        grpcOptions: {
          'ssl-target-name-override': 'peer0.org1.example.com',
          'hostnameOverride': 'peer0.org1.example.com'
        }
      }
    },
    orderers: {
      'orderer.example.com': {
        url: 'grpcs://localhost:7050',
        tlsCACerts: { pem: ordererTls },
        grpcOptions: {
          'ssl-target-name-override': 'orderer.example.com',
          'hostnameOverride': 'orderer.example.com'
        }
      }
    }
  };
}

async function connect({ wallet, identityLabel, channelName, chaincodeName }) {
  if (_gateway && _contract) return _contract;
  const gateway = new Gateway();
  const ccp     = buildCcp();
  await gateway.connect(ccp, {
    wallet,
    identity: identityLabel,
    discovery: { enabled: true, asLocalhost: true },
    eventHandlerOptions: { commitTimeout: 120 }
  });
  const network  = await gateway.getNetwork(channelName);
  const contract = network.getContract(chaincodeName);
  _gateway  = gateway;
  _contract = contract;
  return contract;
}

async function disconnect() {
  if (_gateway) {
    await _gateway.disconnect();
    _gateway = null;
    _contract = null;
  }
}

module.exports = { connect, disconnect };
