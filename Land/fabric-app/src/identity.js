const fs = require('fs');
const path = require('path');
const { Wallets } = require('fabric-network');

function firstFileOrThrow(p) {
  if (!fs.existsSync(p)) throw new Error(`Path not found: ${p}`);
  const files = fs.readdirSync(p).filter(f => fs.statSync(path.join(p, f)).isFile());
  if (!files.length) throw new Error(`No files found in ${p}`);
  return path.join(p, files[0]);
}

function readPemOrThrow(p) {
  const pem = fs.readFileSync(p, 'utf8').trim();
  if (!pem.includes('-----BEGIN')) {
    throw new Error(`File is not PEM: ${p}`);
  }
  return pem;
}

async function importOrg1UserToWallet({ walletPath, userLabel, orgMsp }) {
  const wallet = await Wallets.newFileSystemWallet(walletPath);
  const identityExists = await wallet.get(userLabel);
  if (identityExists) return wallet;

  const base = path.resolve(
    __dirname, '..', '..',
    'test-network', 'organizations', 'peerOrganizations',
    'org1.example.com', 'users', 'User1@org1.example.com', 'msp'
  );

  const certFile = firstFileOrThrow(path.join(base, 'signcerts'));
  const keyFile  = firstFileOrThrow(path.join(base, 'keystore'));

  const cert = readPemOrThrow(certFile);
  const key  = fs.readFileSync(keyFile, 'utf8').trim();
  const identity = {
    credentials: { certificate: cert, privateKey: key },
    mspId: orgMsp,
    type: 'X.509'
  };
  await wallet.put(userLabel, identity);
  return wallet;
}

module.exports = { importOrg1UserToWallet };
