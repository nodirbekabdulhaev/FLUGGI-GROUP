"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.named = exports.parseDate = exports.dateOnly = exports.iso = exports.decReq = exports.dec = void 0;
/** Decimal → строка для API (никаких float в деньгах). */
const dec = (v) => v == null ? null : v.toFixed(2);
exports.dec = dec;
const decReq = (v) => v.toFixed(2);
exports.decReq = decReq;
const iso = (d) => (d ? d.toISOString() : null);
exports.iso = iso;
const dateOnly = (d) => d ? d.toISOString().slice(0, 10) : null;
exports.dateOnly = dateOnly;
/** YYYY-MM-DD → Date (UTC-полночь, для колонок @db.Date). */
const parseDate = (v) => v === undefined ? undefined : v === null ? null : new Date(`${v}T00:00:00Z`);
exports.parseDate = parseDate;
const named = (r) => (r ? { id: r.id, name: r.fullName ?? r.nameRu ?? r.name ?? '' } : null);
exports.named = named;
//# sourceMappingURL=serialize.js.map