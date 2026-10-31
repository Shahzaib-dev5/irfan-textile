// Estimates for operator guidance only. Laravel validates and posts decimal amounts.
const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;

export function receiptPreview(line, choice = {}) {
    const source = number(line.source_quantity);
    const output = number(line.output_quantity);
    const normal = number(line.normal_loss_quantity);
    const abnormal = number(line.abnormal_loss_quantity);
    const rejected = line.disposition === 'rejected';
    const quantity = rejected ? 0 : choice.sameUnit ? output : number(line.accepted_service_quantity);
    return { source, output, normal, abnormal, difference: source - output - normal - abnormal,
        allowance: source * number(choice.normalFraction), charge: quantity * number(choice.rate),
        quantity, remaining: number(choice.available) - source };
}

export function lineWarnings(kind, line, choice = {}) {
    if (!choice.id) return [];
    const warnings = [];
    if (kind === 'receipt') {
        const p = receiptPreview(line, choice);
        if (p.source > number(choice.available)) warnings.push('Quantity being closed exceeds outstanding fabric.');
        if (Math.abs(p.difference) > 0.0000005) warnings.push('Returned fabric and losses must equal the issued quantity being closed.');
        if (p.normal > p.allowance + 0.0000005) warnings.push('Normal loss exceeds the route allowance.');
        if (line.disposition === 'rejected' && (p.normal || p.abnormal)) warnings.push('Rejected returns cannot include processing loss.');
    } else {
        const q = number(line.quantity);
        if (q > number(choice.available)) warnings.push('Quantity exceeds the remaining available quantity.');
        if (kind === 'dispatch' && choice.roll && Math.abs(q - number(choice.onHand)) > 0.0000005) warnings.push('Dispatch the entire physical roll. Roll splitting is not available yet.');
    }
    return warnings;
}

export function processingDocument(config) {
    return {
        kind: config.kind, choices: config.choices, defaults: config.defaults,
        lines: [], nextId: 1,
        init() { this.lines = config.lines.map((line) => this.makeLine(line)); },
        makeLine(line = {}) { return { disposition: 'accepted', normal_loss_quantity: '0', abnormal_loss_quantity: '0', quality_status: 'pending', ...this.defaults, ...line, rowId: this.nextId++ }; },
        add() { this.lines.push(this.makeLine()); },
        remove(index) { this.lines.splice(index, 1); },
        choice(line) {
            const key = this.kind === 'dispatch' ? 'source_stock_position_id' : this.kind === 'receipt' ? 'processing_dispatch_line_id' : 'processing_receipt_line_id';
            return this.choices.find((c) => String(c.id) === String(line[key])) || {};
        },
        warnings(line) {
            const warnings = lineWarnings(this.kind, line, this.choice(line));
            const key = this.kind === 'dispatch' ? 'source_stock_position_id' : this.kind === 'receipt' ? 'processing_dispatch_line_id' : 'processing_receipt_line_id';
            const field = this.kind === 'receipt' ? 'source_quantity' : 'quantity';
            const matching = this.lines.filter((other) => String(other[key]) === String(line[key]));
            if (this.choice(line).id && matching.length > 1 && matching.reduce((sum, other) => sum + number(other[field]), 0) > number(this.choice(line).available)) {
                warnings.push('These lines together exceed the remaining quantity for this source.');
            }
            return warnings;
        },
        preview(line) { return receiptPreview(line, this.choice(line)); },
        amount(line) { return this.kind === 'receipt' ? this.preview(line).charge : number(line.quantity) * number(this.choice(line).rate); },
        total() { return this.lines.reduce((sum, line) => sum + this.amount(line), 0); },
        format(value, places = 6) { return number(value).toLocaleString('en-PK', { maximumFractionDigits: places }); },
        step(line) { return String(10 ** -(this.choice(line).places ?? 6)); },
        rejection(line) { if (line.disposition === 'rejected') { line.normal_loss_quantity = '0'; line.abnormal_loss_quantity = '0'; line.accepted_service_quantity = ''; } },
    };
}
