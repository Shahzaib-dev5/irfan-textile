export function salesForm(initial = [], sources = [], positions = [], itemUrl = '') {
    return {
        lines: [], sources, positions, itemUrl, results: [], query: '', searchError: '', serial: 0, searchSequence: 0,
        init() { this.lines = (initial.length ? initial : [{}]).map(row => this.normalize(row)); },
        normalize(row) { return {key: ++this.serial, item_id:'', unit_id:'', quantity:'', unit_price:'', discount_amount:'0', sales_order_line_id:'', stock_position_id:'', base_quantity:'', sales_dispatch_line_id:'', warehouse_location_id:'', lot_number:'', condition_code:'quarantine', sales_invoice_dispatch_match_id:'', sales_return_line_id:'', credited_base_quantity:'0', net_amount:'', ...row}; },
        add() { this.lines.push(this.normalize({})); },
        remove(key) { if(this.lines.length > 1) this.lines = this.lines.filter(row => row.key !== key); },
        async search() {
            const sequence=++this.searchSequence;
            try { const response=await fetch(this.itemUrl+'?q='+encodeURIComponent(this.query),{headers:{Accept:'application/json'}}); if(!response.ok) throw new Error('search'); const rows=await response.json(); if(sequence===this.searchSequence) { this.results=rows; this.searchError=''; } }
            catch { if(sequence===this.searchSequence) this.searchError='Item search is unavailable. Please try again.'; }
        },
        choose(row, item) { if (!item) return; row.item_id=String(item.id); row.unit_id=String(item.unit_id); row.item_label=item.label; row.units=item.units; },
        source(row, field) { return this.sources.find(s => String(s.id)===String(row[field])); },
        positionsFor(row) { const source=this.source(row,'sales_order_line_id'); return this.positions.filter(p => source && p.item_id===source.item_id); },
        estimate(row) { const q=Number(row.quantity), p=Number(row.unit_price), d=Number(row.discount_amount); return Number.isFinite(q*p-d) ? Math.max(0,q*p-d).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}) : '0.00'; }
    };
}
