export type WooPaymentsAccount = {
	connected: boolean;
	test_drive: boolean;
	sandbox: boolean;
	live: boolean;
};

export type WooPaymentsAccountUrls = {
	setup?: string;
};

export type WooPaymentsDocuments = {
	enabled: boolean;
	has_submitted_vat_data: boolean;
	country: string;
};

export type WooPaymentsAccountResponse = {
	account: WooPaymentsAccount | null;
	documents?: WooPaymentsDocuments;
	urls: WooPaymentsAccountUrls;
};
