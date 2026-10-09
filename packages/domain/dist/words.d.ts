/** Сумма прописью для договоров (рус.): «Семь миллионов пятьсот тысяч сумов 00 тийинов». */
type Forms = [string, string, string];
/** Форма слова для числа: 1 сум, 2 сума, 5 сумов. */
export declare function plural(n: number, [one, few, many]: Forms): string;
/** Целое число прописью. `feminine` — для единиц женского рода. */
export declare function numberInWords(value: number, feminine?: boolean): string;
/** «7 500 000.00 UZS» → «Семь миллионов пятьсот тысяч сумов 00 тийинов». */
export declare function amountInWords(amount: string | number, currency: string): string;
export {};
