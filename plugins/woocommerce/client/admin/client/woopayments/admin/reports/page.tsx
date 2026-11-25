/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { BalanceReport } from './balance-report';
import { FeesReport } from './fees-report';
import type { ReportsTab } from './types';
import { isSettingsPaymentsSearch, navigateReportsRoute } from './utils';
import '../dataviews.scss';
import './style.scss';

type WooPaymentsReportsPageProps = {
	now?: Date;
};

const normalizeReportsTab = ( tab: string | null ): ReportsTab =>
	tab === 'fees' ? 'fees' : 'balance';

const getReportsTabFromSearch = ( search: string ): ReportsTab => {
	const params = new URLSearchParams( search );
	const reportTab = params.get( 'report_tab' );

	if ( reportTab ) {
		return normalizeReportsTab( reportTab );
	}

	return isSettingsPaymentsSearch( search )
		? 'balance'
		: normalizeReportsTab( params.get( 'tab' ) );
};

export const WooPaymentsReportsPage = ( {
	now = new Date(),
}: WooPaymentsReportsPageProps ) => {
	const stableNow = useRef( now ).current;
	const location = useLocation();
	const navigate = useNavigate();
	const activeTab = getReportsTabFromSearch( location.search );
	const balanceTabId = useId();
	const feesTabId = useId();
	const balancePanelId = useId();
	const feesPanelId = useId();
	const balanceTabRef = useRef< HTMLButtonElement >( null );
	const feesTabRef = useRef< HTMLButtonElement >( null );
	const [ headerActions, setHeaderActions ] = useState< HTMLElement | null >(
		null
	);

	useEffect( () => {
		recordEvent( 'page_view', {
			path: 'payments_reports',
			tab: activeTab,
		} );
		// Mount-only: the page view is recorded once.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const selectTab = ( nextTab: ReportsTab ) => {
		if ( nextTab === activeTab ) {
			return;
		}

		recordEvent( 'wcpay_reports_tab_change', {
			from_tab: activeTab,
			to_tab: nextTab,
		} );
		navigateReportsRoute(
			location.search,
			navigate,
			nextTab === 'fees' ? 'report_tab=fees' : ''
		);
	};

	const focusTab = ( nextTab: ReportsTab ) => {
		selectTab( nextTab );

		if ( nextTab === 'balance' ) {
			balanceTabRef.current?.focus();
			return;
		}

		feesTabRef.current?.focus();
	};

	const handleTabKeyDown = ( event: KeyboardEvent< HTMLButtonElement > ) => {
		if ( event.nativeEvent.isComposing ) {
			return;
		}

		if ( [ 'ArrowLeft', 'ArrowUp', 'Home' ].includes( event.key ) ) {
			event.preventDefault();
			focusTab( 'balance' );
		}

		if ( [ 'ArrowRight', 'ArrowDown', 'End' ].includes( event.key ) ) {
			event.preventDefault();
			focusTab( 'fees' );
		}
	};

	return (
		<div className="woocommerce-woopayments-reports">
			<h1 className="screen-reader-text">
				{ __( 'Reports', 'woocommerce' ) }
			</h1>
			{ /* Client 11.2.0 `reports/header.tsx`: the intro, with the active report's actions beside it. */ }
			<div className="woocommerce-woopayments-reports__header">
				<p className="woocommerce-woopayments-reports__intro">
					{ __( 'View your reconciliation reports.', 'woocommerce' ) }
				</p>
				<div
					className="woocommerce-woopayments-reports__header-actions"
					ref={ setHeaderActions }
				/>
			</div>
			<div
				className="woocommerce-woopayments-reports__tabs"
				role="tablist"
				aria-label={ __( 'Reports', 'woocommerce' ) }
			>
				<button
					type="button"
					ref={ balanceTabRef }
					id={ balanceTabId }
					role="tab"
					aria-selected={ activeTab === 'balance' }
					aria-controls={ balancePanelId }
					tabIndex={ activeTab === 'balance' ? 0 : -1 }
					onClick={ () => selectTab( 'balance' ) }
					onKeyDown={ handleTabKeyDown }
				>
					{ __( 'Balance', 'woocommerce' ) }
				</button>
				<button
					type="button"
					ref={ feesTabRef }
					id={ feesTabId }
					role="tab"
					aria-selected={ activeTab === 'fees' }
					aria-controls={ feesPanelId }
					tabIndex={ activeTab === 'fees' ? 0 : -1 }
					onClick={ () => selectTab( 'fees' ) }
					onKeyDown={ handleTabKeyDown }
				>
					{ __( 'Fees', 'woocommerce' ) }
				</button>
			</div>
			<div
				id={ balancePanelId }
				role="tabpanel"
				aria-labelledby={ balanceTabId }
				hidden={ activeTab !== 'balance' }
			>
				{ activeTab === 'balance' && (
					<BalanceReport now={ stableNow } />
				) }
			</div>
			<div
				id={ feesPanelId }
				role="tabpanel"
				aria-labelledby={ feesTabId }
				hidden={ activeTab !== 'fees' }
			>
				{ activeTab === 'fees' && (
					<FeesReport
						now={ stableNow }
						headerActions={ headerActions }
					/>
				) }
			</div>
		</div>
	);
};
