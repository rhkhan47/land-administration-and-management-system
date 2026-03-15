'use strict';
const { WorkloadModuleBase } = require('@hyperledger/caliper-core');
class LandWorkload extends WorkloadModuleBase {
  constructor() { super(); }
  async initializeWorkloadModule(workerIndex, totalWorkers, roundIndex, roundArguments, sutAdapter, sutContext) {
    await super.initializeWorkloadModule(workerIndex, totalWorkers, roundIndex, roundArguments, sutAdapter, sutContext);
    const seller = `user_seller_${this.workerIndex}`;
    const buyer = `user_buyer_${this.workerIndex}`;
    const regSeller = {
      contractId: this.roundArguments.contractId,
      contractFunction: 'registerUser',
      invokerIdentity: 'User1',
      contractArguments: [seller, `NID-S-${this.workerIndex}`, JSON.stringify({ name: 'Seller', dob: '1990-01-01', address: 'Addr' })],
      readOnly: false
    };
    const regBuyer = {
      contractId: this.roundArguments.contractId,
      contractFunction: 'registerUser',
      invokerIdentity: 'User1',
      contractArguments: [buyer, `NID-B-${this.workerIndex}`, JSON.stringify({ name: 'Buyer', dob: '1991-01-01', address: 'Addr' })],
      readOnly: false
    };
    await this.sutAdapter.sendRequests(regSeller);
    await this.sutAdapter.sendRequests(regBuyer);
    const appSeller = {
      contractId: this.roundArguments.contractId,
      contractFunction: 'approveUser',
      invokerIdentity: 'User1',
      contractArguments: [seller],
      readOnly: false
    };
    const appBuyer = {
      contractId: this.roundArguments.contractId,
      contractFunction: 'approveUser',
      invokerIdentity: 'User1',
      contractArguments: [buyer],
      readOnly: false
    };
    await this.sutAdapter.sendRequests(appSeller);
    await this.sutAdapter.sendRequests(appBuyer);
    for (let i = 0; i < this.roundArguments.assets; i++) {
      const landId = `land_${this.workerIndex}_${i}`;
      const addLand = {
        contractId: this.roundArguments.contractId,
        contractFunction: 'addLand',
        invokerIdentity: 'User1',
        contractArguments: [landId, JSON.stringify({ location: `Loc-${i}`, area: 100 + i }), '', seller],
        readOnly: false
      };
      await this.sutAdapter.sendRequests(addLand);
      const approveLand = {
        contractId: this.roundArguments.contractId,
        contractFunction: 'approveLand',
        invokerIdentity: 'User1',
        contractArguments: [landId],
        readOnly: false
      };
      await this.sutAdapter.sendRequests(approveLand);
      const postForSale = {
        contractId: this.roundArguments.contractId,
        contractFunction: 'postForSale',
        invokerIdentity: 'User1',
        contractArguments: [landId, String(1000 + i)],
        readOnly: false
      };
      await this.sutAdapter.sendRequests(postForSale);
    }
  }
  async submitTransaction() {
    const i = Math.floor(Math.random() * this.roundArguments.assets);
    const landId = `land_${this.workerIndex}_${i}`;
    const buyer = `user_buyer_${this.workerIndex}`;
    const req = {
      contractId: this.roundArguments.contractId,
      contractFunction: 'buyRequest',
      invokerIdentity: 'User1',
      contractArguments: [landId, buyer, String(1200 + i)],
      readOnly: false
    };
    await this.sutAdapter.sendRequests(req);
  }
}
function createWorkloadModule() { return new LandWorkload(); }
module.exports.createWorkloadModule = createWorkloadModule;
