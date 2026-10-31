export function productionForm(config = {}) {
    let next = 0;
    const row = (value = {}) => ({ ...value, rowId: next++ });
    const sections = ['lines', 'materials', 'outputs', 'wastage', 'costs'];
    const form = { ...config };
    for (const key of sections) form[key] = (config[key] || []).map(row);
    return {
        ...form,
        add(section) { this[section].push(row(this.defaults?.[section] || {})); },
        remove(section, index) {
            if (['lines', 'materials', 'outputs'].includes(section) && this[section].length === 1) return;
            const reference = section === 'materials' ? ['wastage', 'material_index'] : section === 'outputs' ? ['costs', 'output_index'] : null;
            if (reference) for (const r of this[reference[0]]) {
                const value = r[reference[1]];
                if (value === '' || value == null) continue;
                if (Number(value) === index) r[reference[1]] = '';
                else if (Number(value) > index) r[reference[1]] = String(Number(value) - 1);
            }
            this[section].splice(index, 1);
        },
        fractions() { return this.outputs.reduce((sum, r) => sum + (Number(r.cost_allocation_fraction) || 0), 0).toFixed(10); },
        position(row) { return (this.positions || []).find(p => String(p.id) === String(row.stock_position_id)) || {}; },
        available(row) { const p = this.position(row); return p.id ? 'Available: ' + p.available + ' ' + p.unit : 'Choose the actual source lot / roll.'; },
    };
}
