export function directPurchase(config) {
    return {
        ...config,
        add() { this.lines.push(JSON.parse(JSON.stringify(this.blank))); },
        mode(line) { line.item_id = ''; line.new_item = {...this.blank.new_item}; },
        unit(line) { return this.units.find(u => String(u.id) === String(line.unit_id)); },
        compatible(line, unit) {
            const item = this.items.find(i => String(i.id) === String(line.item_id));
            const type = line.mode === 'new' ? line.new_item.item_type : item?.item_type;
            return !['grey_fabric','processed_fabric'].includes(type) || ['M','PCS','SET','THAAN'].includes(unit.code);
        },
        changeUnit(line) { line.rate_unit_id = line.unit_id; line.rate_quantity = ''; },
        sameRate(line) { return !line.rate_unit_id || String(line.rate_unit_id) === String(line.unit_id); },
        total() { return this.lines.reduce((sum,line) => sum + (Number(this.sameRate(line) ? line.quantity : line.rate_quantity) || 0) * (Number(line.unit_cost) || 0), 0); },
    };
}
