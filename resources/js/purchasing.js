// Read-only guidance; stock, matching and money validation remain on the server.
export function purchasingDocument(config) {
    return {
        lines: [], nextId: 1, kind: config.kind,
        orderInfo: config.orderInfo, receiptInfo: config.receiptInfo, positionInfo: config.positionInfo,
        init() { this.lines = config.lines.map((line) => this.makeLine(line)); },
        makeLine(line = {}) { return { quality_status: 'pending', discount_amount: '0', rejected_quantity: '0', ...config.defaults, ...line, rowId: this.nextId++ }; },
        add() { this.lines.push(this.makeLine()); },
        remove(index) { this.lines.splice(index, 1); },
        order(line) { return this.orderInfo[line.purchase_order_line_id] || {}; },
        receipt(line) { return this.receiptInfo[line.goods_receipt_line_id] || {}; },
        positionsFor(line) {
            const source = this.receipt(line);
            return this.positionInfo.filter((p) => p.lot === source.lot && p.roll === source.roll && (!p.tracked || Number(p.onHand) <= Number(source.returnable)));
        },
        position(line) { return this.positionsFor(line).find((p) => String(p.id) === String(line.stock_position_id)) || {}; },
        selectReceipt(line) {
            if (this.kind === 'bill') line.unit_price = this.receipt(line).cost || '';
            if (this.kind === 'return') { const positions = this.positionsFor(line); line.stock_position_id = positions.length === 1 ? positions[0].id : ''; }
        },
        returnLimit(line) { return Math.max(0, Math.min(Number(this.position(line).available || 0), Number(this.receipt(line).returnable || 0))); },
    };
}
