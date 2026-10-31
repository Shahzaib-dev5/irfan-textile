export function packingForm(config) {
    let key = 0;
    const material = () => ({ product_pack_spec_line_id: '', stock_position_id: '', quantity: '' });
    const output = () => ({ item_id: config.operation === 'pack' ? (config.packedItem || '') : '', warehouse_location_id: '', lot_number: '', quantity: '', quality_status: 'pending', cost_allocation_fraction: '1', packages: [{ package_code: '', quantity: '', gross_weight_kg: '' }] });
    const identify = (line) => ({ ...line, key: ++key, packages: (line.packages || []).map(p => ({ ...p, key: ++key })) });
    return {
        ...config,
        materials: (config.materials || [material()]).map(identify), outputs: (config.outputs || [output()]).map(identify),
        add(section) { this[section].push(identify(section === 'materials' ? material() : output())); },
        remove(section, index) { if (this[section].length > 1) this[section].splice(index, 1); },
        addPackage(line) { line.packages.push({ key: ++key, package_code: '', quantity: '', gross_weight_kg: '' }); },
        removePackage(line, index) { if (line.packages.length > 1) line.packages.splice(index, 1); },
        available(line) { const p = this.positions.find(p => String(p.id) === String(line.stock_position_id)); return p ? p.label : 'Choose the actual source lot.'; },
        containerTotal(line) { return line.packages.reduce((s, p) => s + (Number(p.quantity) || 0), 0); },
    };
}
export function packRecipe(config) {
    let key = 0;
    return { ...config, lines: config.lines.map(l => ({ ...l, key: ++key })), add() { this.lines.push({ key: ++key, component_item_id: '', component_kind: 'saleable_content', quantity_per_pack: '1' }); }, remove(i) { if (this.lines.length > 1) this.lines.splice(i, 1); }, kind(line) { line.component_kind = this.components.find(i => String(i.id) === String(line.component_item_id))?.kind || 'saleable_content'; } };
}
