/**
 * External dependencies
 */
import { Card } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { List, TaskItem } from '@woocommerce/experimental';

/**
 * Internal dependencies
 */
import { saveOption } from '../../../settings/data/actions';
import type {
	WooPaymentsOverviewTask,
	WooPaymentsOverviewTasksVisibility,
} from '../types';
import { getVisibleOverviewTasks } from './overview-tasks';

const DAY_IN_MS = 24 * 60 * 60 * 1000;

const normalizeVisibility = (
	visibility: WooPaymentsOverviewTasksVisibility
): WooPaymentsOverviewTasksVisibility => ( {
	dismissed_todo_tasks: [ ...visibility.dismissed_todo_tasks ],
	deleted_todo_tasks: [ ...visibility.deleted_todo_tasks ],
	remind_me_later_todo_tasks: {
		...visibility.remind_me_later_todo_tasks,
	},
} );

export const OverviewTaskList = ( {
	tasks,
	visibility,
}: {
	tasks: WooPaymentsOverviewTask[];
	visibility: WooPaymentsOverviewTasksVisibility;
} ) => {
	const [ localVisibility, setLocalVisibility ] = useState( () =>
		normalizeVisibility( visibility )
	);
	const [ announcement, setAnnouncement ] = useState( '' );
	const sectionRef = useRef< HTMLElement | null >( null );
	const headingRef = useRef< HTMLHeadingElement | null >( null );
	const shouldMoveFocusRef = useRef( false );
	const visibleTasks = useMemo(
		() => getVisibleOverviewTasks( tasks, localVisibility ),
		[ tasks, localVisibility ]
	);

	useEffect( () => {
		if ( ! shouldMoveFocusRef.current ) {
			return;
		}

		shouldMoveFocusRef.current = false;

		// After the task menu closes and hands focus back, and skipping the task that is animating out.
		const view = sectionRef.current?.ownerDocument.defaultView;
		const frame = view?.requestAnimationFrame( () => {
			const focusTarget =
				sectionRef.current?.querySelector< HTMLElement >(
					'.woocommerce-task-list__item:not(.woocommerce-list__item-exit) .woocommerce-task-list__item-action:not([disabled])'
				) ?? headingRef.current;

			focusTarget?.focus();
		} );

		return () => {
			if ( frame !== undefined ) {
				view?.cancelAnimationFrame( frame );
			}
		};
	}, [ visibleTasks ] );

	const createUndoNotice = ( message: string, undo: () => void ) => {
		dispatch( 'core/notices' ).createSuccessNotice( message, {
			actions: [
				{
					label: __( 'Undo', 'woocommerce' ),
					onClick: undo,
				},
			],
		} );
	};

	const dismissTask = (
		task: WooPaymentsOverviewTask,
		kind: 'dismissed_todo_tasks' | 'deleted_todo_tasks',
		optionName: string,
		message: string
	) => {
		const previousVisibility = localVisibility;
		const nextItems = Array.from(
			new Set( [ ...localVisibility[ kind ], task.key ] )
		);
		const nextVisibility = {
			...localVisibility,
			[ kind ]: nextItems,
		};

		shouldMoveFocusRef.current = true;
		setLocalVisibility( nextVisibility );
		setAnnouncement( message );
		void saveOption( optionName, nextItems );
		createUndoNotice( message, () => {
			shouldMoveFocusRef.current = true;
			setLocalVisibility( previousVisibility );
			setAnnouncement( __( 'Task restored.', 'woocommerce' ) );
			void saveOption( optionName, previousVisibility[ kind ] );
		} );
	};

	const snoozeTask = ( task: WooPaymentsOverviewTask ) => {
		const previousVisibility = localVisibility;
		const nextReminders = {
			...localVisibility.remind_me_later_todo_tasks,
			[ task.key ]: Date.now() + DAY_IN_MS,
		};
		const nextVisibility = {
			...localVisibility,
			remind_me_later_todo_tasks: nextReminders,
		};
		const message = __( 'Task postponed until tomorrow.', 'woocommerce' );

		shouldMoveFocusRef.current = true;
		setLocalVisibility( nextVisibility );
		setAnnouncement( message );
		void saveOption(
			'woocommerce_remind_me_later_todo_tasks',
			nextReminders
		);
		createUndoNotice( message, () => {
			shouldMoveFocusRef.current = true;
			setLocalVisibility( previousVisibility );
			setAnnouncement( __( 'Task restored.', 'woocommerce' ) );
			void saveOption(
				'woocommerce_remind_me_later_todo_tasks',
				previousVisibility.remind_me_later_todo_tasks
			);
		} );
	};

	if ( visibleTasks.length === 0 && ! announcement ) {
		return null;
	}

	// Client 11.1.0 `overview/task-list/index.js:167-213` and `overview/index.js:346-357`: no visible heading.
	return (
		<Card
			as="section"
			ref={ sectionRef }
			className="woocommerce-woopayments-overview__tasks"
			aria-labelledby="woocommerce-woopayments-overview-tasks-heading"
		>
			<h2
				id="woocommerce-woopayments-overview-tasks-heading"
				className="screen-reader-text"
				ref={ headingRef }
				tabIndex={ -1 }
			>
				{ __( 'Things to do', 'woocommerce' ) }
			</h2>
			<p className="screen-reader-text" role="status" aria-live="polite">
				{ announcement }
			</p>
			{ visibleTasks.length > 0 && (
				// The client's CollapsibleList folds after five tasks; the Overview builds at most three.
				<List className="woocommerce-woopayments-overview__task-list">
					{ visibleTasks.map( ( task ) => (
						<TaskItem
							key={ task.key }
							data-key={ task.key }
							data-urgent={ task.isUrgent ? 'true' : undefined }
							title={ task.title }
							// TaskItem renders its content as a node, though its type says string.
							content={ task.content as string }
							actionLabel={ task.actionLabel }
							completed={ !! task.completed }
							inProgress={ false }
							inProgressLabel=""
							level={ ( task.level ?? 3 ) as 1 | 2 | 3 }
							expanded
							showActionButton={ task.showActionButton !== false }
							action={ () => {
								task.onClick?.();
								if ( task.href ) {
									window.location.assign( task.href );
								}
							} }
							onDismiss={
								task.isDismissable
									? () =>
											dismissTask(
												task,
												'dismissed_todo_tasks',
												'woocommerce_dismissed_todo_tasks',
												__(
													'Task dismissed.',
													'woocommerce'
												)
											)
									: undefined
							}
							onSnooze={
								task.allowSnooze
									? () => snoozeTask( task )
									: undefined
							}
							onDelete={
								task.isDeletable && task.completed
									? () =>
											dismissTask(
												task,
												'deleted_todo_tasks',
												'woocommerce_deleted_todo_tasks',
												__(
													'Task deleted.',
													'woocommerce'
												)
											)
									: undefined
							}
						/>
					) ) }
				</List>
			) }
		</Card>
	);
};
