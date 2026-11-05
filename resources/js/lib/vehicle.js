// Общие помощники формы автомобиля (раздел «Автомобили» и гараж клиента).
export const emptyVehicle = () => ({ plate: '', plate_mode: 'ru', make: '', model: '', vin: '', year: null, color: '', notes: '' });

export const vehicleToForm = (v) => ({
    plate: v.plate || '', plate_mode: v.plate ? 'ru' : 'none', make: v.make || '', model: v.model || '',
    vin: v.vin || '', year: v.year || null, color: v.color || '', notes: v.notes || '',
});

/** plate_mode → no_plate, пустые строки → null. */
export const vehiclePayload = ({ plate_mode, ...d }) => ({
    ...d,
    plate: plate_mode === 'none' ? null : d.plate,
    no_plate: plate_mode === 'none',
    vin: d.vin || null, year: d.year || null, color: d.color || null, notes: d.notes || null,
});
