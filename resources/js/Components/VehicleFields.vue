<script setup>
import PlateInput from './PlateInput.vue';
import CarMakeModel from './CarMakeModel.vue';

// Поля карточки автомобиля (госномер, марка/модель из справочника, VIN, год, цвет, примечание).
// form — useForm с полями plate, plate_mode, make, model, vin, year, color, notes.
defineProps({ form: { type: Object, required: true } });
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2"><label class="label">Госномер</label><PlateInput v-model="form.plate" v-model:mode="form.plate_mode" /><div class="error" v-if="form.errors.plate">{{ form.errors.plate }}</div></div>
        <CarMakeModel v-model:make="form.make" v-model:model="form.model" required />
        <div class="error sm:col-span-2" v-if="form.errors.make || form.errors.model">{{ form.errors.make || form.errors.model }}</div>
        <div class="sm:col-span-2"><label class="label">VIN / номер кузова</label><input v-model="form.vin" class="input tabular uppercase" maxlength="20" /><div class="error" v-if="form.errors.vin">{{ form.errors.vin }}</div></div>
        <div><label class="label">Год выпуска</label><input v-model.number="form.year" type="number" min="1950" class="input tabular" /><div class="error" v-if="form.errors.year">{{ form.errors.year }}</div></div>
        <div><label class="label">Цвет</label><input v-model="form.color" class="input" maxlength="32" /></div>
        <div class="sm:col-span-2"><label class="label">Примечание</label><input v-model="form.notes" class="input" maxlength="255" /></div>
    </div>
</template>
