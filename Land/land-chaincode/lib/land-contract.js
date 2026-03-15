// SPDX-License-Identifier: Apache-2.0
/*
 * Complete land administration chaincode implementing registration, approvals,
 * land management (add, sale, mortgage, lease) and additional queries such as
 * pending users/lands, available mortgage/lease, user details and sale
 * cancellation.  Uses deterministic timestamps to ensure endorsements match.
 */
"use strict";

const { Contract } = require("fabric-contract-api");

class LandContract extends Contract {

  async initLedger(ctx) {
    console.info("Ledger initialization complete");
  }

  // composite key helpers
  _userKey(ctx, userId) { return ctx.stub.createCompositeKey('User', [userId]); }
  _landKey(ctx, landId) { return ctx.stub.createCompositeKey('Land', [landId]); }
  _txTimestamp(ctx) {
    const ts = ctx.stub.getTxTimestamp();
    return ts.seconds ? ts.seconds.toNumber() || ts.seconds.low : 0;
  }

  // USER FUNCTIONS
  async registerUser(ctx, userId, nationalId, personalDetails) {
    const key = this._userKey(ctx, userId);
    const existing = await ctx.stub.getState(key);
    if (existing && existing.length > 0) {
      throw new Error(`User ${userId} already exists`);
    }
    const user = {
      userId,
      nationalId,
      personalDetails: JSON.parse(personalDetails),
      status: 'Pending'
    };
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(user)));
    ctx.stub.setEvent('UserRegistered', Buffer.from(JSON.stringify(user)));
    return user;
  }

  async approveUser(ctx, userId) {
    const key = this._userKey(ctx, userId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) {
      throw new Error(`User ${userId} does not exist`);
    }
    const user = JSON.parse(data.toString());
    user.status = 'Active';
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(user)));
    ctx.stub.setEvent('UserApproved', Buffer.from(JSON.stringify(user)));
    return user;
  }

  async readUser(ctx, userId) {
    const key = this._userKey(ctx, userId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`User ${userId} does not exist`);
    return JSON.parse(data.toString());
  }

  async queryPendingUsers(ctx) {
    const iterator = await ctx.stub.getStateByPartialCompositeKey('User', []);
    const results = [];
    while (true) {
      const item = await iterator.next();
      if (item.value && item.value.value) {
        const user = JSON.parse(item.value.value.toString('utf8'));
        if (user.status === 'Pending') results.push(user);
      }
      if (item.done) {
      await iterator.close(); break; }
    }
    return results;
  }

  // LAND FUNCTIONS
  async addLand(ctx, landId, location, deedCid, ownerId) {
    const ownerKey = this._userKey(ctx, ownerId);
    const ownerBytes = await ctx.stub.getState(ownerKey);
    if (!ownerBytes || ownerBytes.length === 0) {
      throw new Error(`Owner ${ownerId} does not exist`);
    }
    const owner = JSON.parse(ownerBytes.toString());
    if (owner.status !== 'Active') {
      throw new Error(`Owner ${ownerId} is not approved`);
    }
    const landKey = this._landKey(ctx, landId);
    const existing = await ctx.stub.getState(landKey);
    if (existing && existing.length > 0) throw new Error(`Land ${landId} already exists`);
    const land = {
      landId,
      location: JSON.parse(location),
      deedCid,
      ownerId,
      status: 'Pending',
      askingPrice: 0,
      mortgage: null,
      lease: null,
      history: []
    };
    await ctx.stub.putState(landKey, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('LandAdded', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async approveLand(ctx, landId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'Pending') {
      throw new Error(`Land ${landId} is not awaiting approval`);
    }
    land.status = 'Owned';
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'Approved', timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('LandApproved', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async postForSale(ctx, landId, price) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'Owned') {
      throw new Error(`Land ${landId} is not available for sale`);
    }
    land.status = 'ForSale';
    land.askingPrice = parseFloat(price);
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'PostedForSale', price: land.askingPrice, timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('LandForSale', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async cancelSale(ctx, landId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'ForSale') {
      throw new Error(`Land ${landId} is not currently for sale`);
    }
    land.status = 'Owned';
    land.askingPrice = 0;
    delete land.pendingBuyer;
    delete land.offerPrice;
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'SaleCancelled', timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('SaleCancelled', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async buyRequest(ctx, landId, buyerId, offeredPrice) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'ForSale') {
      throw new Error(`Land ${landId} is not currently for sale`);
    }
    const buyerKey = this._userKey(ctx, buyerId);
    const buyerData = await ctx.stub.getState(buyerKey);
    if (!buyerData || buyerData.length === 0) throw new Error(`Buyer ${buyerId} does not exist`);
    const buyer = JSON.parse(buyerData.toString());
    if (buyer.status !== 'Active') throw new Error(`Buyer ${buyerId} is not approved`);
    land.status = 'PendingSale';
    land.pendingBuyer = buyerId;
    land.offerPrice = parseFloat(offeredPrice);
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'BuyRequest', buyerId, offer: land.offerPrice, timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('BuyRequest', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async confirmSale(ctx, landId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'PendingSale') {
      throw new Error(`Land ${landId} is not awaiting confirmation`);
    }
    land.ownerId = land.pendingBuyer;
    land.status  = 'Owned';
    land.askingPrice = 0;
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'SaleConfirmed', newOwner: land.ownerId, timestamp: ts });
    delete land.pendingBuyer;
    delete land.offerPrice;
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('SaleConfirmed', Buffer.from(JSON.stringify(land)));
    return land;
  }

  // Mortgage
  async postMortgage(ctx, landId, principal, duration, escrow) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'Owned') {
      throw new Error(`Land ${landId} cannot be mortgaged in status ${land.status}`);
    }
    land.status = 'MortgagePending';
    land.mortgage = {
      principal: parseFloat(principal),
      duration: parseInt(duration, 10),
      escrowAddress: escrow,
      mortgageeId: null,
      status: 'Pending'
    };
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'PostMortgage', principal: land.mortgage.principal, timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('MortgagePosted', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async acceptMortgage(ctx, landId, mortgageeId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (!land.mortgage || land.mortgage.status !== 'Pending') {
      throw new Error(`No pending mortgage found for land ${landId}`);
    }
    const mortgageeKey = this._userKey(ctx, mortgageeId);
    const mortBytes = await ctx.stub.getState(mortgageeKey);
    if (!mortBytes || mortBytes.length === 0) throw new Error(`Mortgagee ${mortgageeId} does not exist`);
    land.mortgage.mortgageeId = mortgageeId;
    land.mortgage.status = 'Active';
    land.status = 'Mortgaged';
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'MortgageAccepted', mortgageeId, timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('MortgageAccepted', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async repayMortgage(ctx, landId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (!land.mortgage || land.mortgage.status !== 'Active') {
      throw new Error(`No active mortgage to repay for land ${landId}`);
    }
    land.mortgage.status = 'Repaid';
    land.status = 'Owned';
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'MortgageRepaid', timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('MortgageRepaid', Buffer.from(JSON.stringify(land)));
    return land;
  }

  // Lease
  async postLease(ctx, landId, duration, rent) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (land.status !== 'Owned') {
      throw new Error(`Land ${landId} cannot be leased in status ${land.status}`);
    }
    land.status = 'LeasePending';
    land.lease = {
      duration: parseInt(duration, 10),
      rent: parseFloat(rent),
      tenantId: null,
      status: 'Pending',
      payments: []
    };
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'PostLease', duration: land.lease.duration, rent: land.lease.rent, timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('LeasePosted', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async acceptLease(ctx, landId, tenantId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (!land.lease || land.lease.status !== 'Pending') {
      throw new Error(`No pending lease found for land ${landId}`);
    }
    const tenantKey = this._userKey(ctx, tenantId);
    const tenantBytes = await ctx.stub.getState(tenantKey);
    if (!tenantBytes || tenantBytes.length === 0) throw new Error(`Tenant ${tenantId} does not exist`);
    land.lease.tenantId = tenantId;
    land.lease.status = 'Active';
    land.status = 'Leased';
    const ts = this._txTimestamp(ctx);
    land.history.push({ action: 'LeaseAccepted', tenantId, timestamp: ts });
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('LeaseAccepted', Buffer.from(JSON.stringify(land)));
    return land;
  }

  async payRent(ctx, landId, tenantId, amount) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    const land = JSON.parse(data.toString());
    if (!land.lease || land.lease.status !== 'Active') {
      throw new Error(`No active lease found for land ${landId}`);
    }
    if (land.lease.tenantId !== tenantId) {
      throw new Error(`Tenant ${tenantId} is not leasing land ${landId}`);
    }
    const ts = this._txTimestamp(ctx);
    land.lease.payments.push({ amount: parseFloat(amount), timestamp: ts });
    if (land.lease.payments.length >= land.lease.duration) {
      land.lease.status = 'Completed';
      land.status = 'Owned';
      land.history.push({ action: 'LeaseCompleted', timestamp: ts });
    }
    await ctx.stub.putState(key, Buffer.from(JSON.stringify(land)));
    ctx.stub.setEvent('RentPaid', Buffer.from(JSON.stringify({ landId, tenantId, amount })));
    return land;
  }

  // READ and QUERY
  async readLand(ctx, landId) {
    const key = this._landKey(ctx, landId);
    const data = await ctx.stub.getState(key);
    if (!data || data.length === 0) throw new Error(`Land ${landId} does not exist`);
    return JSON.parse(data.toString());
  }

  async queryAvailableLand(ctx) {
    const query = { selector: { status: 'ForSale' } };
    const iterator = await ctx.stub.getQueryResult(JSON.stringify(query));
    const results = [];
    while (true) {
      const item = await iterator.next();
      if (item.value && item.value.value) {
        const land = JSON.parse(item.value.value.toString('utf8'));
        results.push(land);
      }
      if (item.done) {
        await iterator.close();
        break;
      }
    }
    return results;
  }

  async queryOwnedLand(ctx, ownerId) {
    const query = { selector: { ownerId } };
    const iterator = await ctx.stub.getQueryResult(JSON.stringify(query));
    const results = [];
    while (true) {
      const item = await iterator.next();
      if (item.value && item.value.value) {
        const land = JSON.parse(item.value.value.toString('utf8'));
        results.push(land);
      }
      if (item.done) {
        await iterator.close();
        break;
      }
    }
    return results;
  }

  async queryAvailableForMortgage(ctx) {
    const query = { selector: { status: 'MortgagePending' } };
    const iterator = await ctx.stub.getQueryResult(JSON.stringify(query));
    const results = [];
    while (true) {
      const item = await iterator.next();
      if (item.value && item.value.value) {
        const land = JSON.parse(item.value.value.toString('utf8'));
        results.push(land);
      }
      if (item.done) { await iterator.close(); break; }
    }
    return results;
  }

  async queryAvailableForLease(ctx) {
    const query = { selector: { status: 'LeasePending' } };
    const iterator = await ctx.stub.getQueryResult(JSON.stringify(query));
    const results = [];
    while (true) {
      const item = await iterator.next();
      if (item.value && item.value.value) {
        const land = JSON.parse(item.value.value.toString('utf8'));
        results.push(land);
      }
      if (item.done) { await iterator.close(); break; }
    }
    return results;
  }

  async queryPendingLand(ctx) {
    const iterator = await ctx.stub.getStateByPartialCompositeKey('Land', []);
    const results = [];
    while (true) {
      const item = await iterator.next();
      if (item.value && item.value.value) {
        const land = JSON.parse(item.value.value.toString('utf8'));
        if (land.status === 'Pending') results.push(land);
      }
      if (item.done) { await iterator.close(); break; }
    }
    return results;
  }
}

module.exports = LandContract;
