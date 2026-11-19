export function catalogItem(config) {
    return {
        ...config,
        isFabric() { return ['grey_fabric', 'processed_fabric'].includes(this.type); },
        canTrackRolls() { return this.isFabric() && this.units.some((u) => String(u.id) === String(this.unit) && u.code === 'M' && u.dimension === 'length'); },
        changeUnit() {
            if (this.readOnly || this.identityLocked) return;
            if (!this.canTrackRolls()) this.rolls = false;
        },
        compatible(unit) {
            if (this.isFabric()) return (unit.code === 'M' && unit.dimension === 'length') || (['PCS','SET','THAAN'].includes(unit.code) && unit.dimension === 'count' && unit.decimal_places === 0);
            if (this.type === 'finished') return ['SUIT', 'PACK'].includes(unit.code) && unit.dimension === 'count';
            if (this.type === 'service') return unit.dimension === 'service';
            return unit.dimension !== 'service' && !['SUIT','PACK'].includes(unit.code);
        },
        unitChoices() { return this.units.filter((u) => ((u.is_active || !this.active) && this.compatible(u)) || String(u.id) === String(this.unit)); },
        changeType(type) {
            if (this.readOnly || this.identityLocked) return;
            this.type = type;
            this.applySuggested();
        },
        applySuggested() {
            if (this.readOnly || this.identityLocked) return;
            const choices = this.units.filter((u) => u.is_active && this.compatible(u));
            const preferred = this.isFabric() ? 'M' : this.type === 'finished' ? 'SUIT' : this.type === 'service' ? 'SVC' : null;
            this.unit = preferred ? (choices.find((u) => u.code === preferred)?.id || '') : (choices.some((u) => String(u.id) === String(this.unit)) ? this.unit : '');
            this.stocked = this.type !== 'service';
            this.rolls = false;
        },
        unitLabel() { const u = this.units.find((u) => String(u.id) === String(this.unit)); return u ? u.code + ' / ' + u.name : 'Choose a unit'; },
        typeHelp() { return this.types[this.type]?.help || 'Choose what this item represents.'; },
    };
}
