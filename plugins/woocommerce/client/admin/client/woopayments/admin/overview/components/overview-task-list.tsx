/**
 * External dependencies
 */
import { Card } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { CollapsibleList, TaskItem } from '@woocommerce/experimental';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewTask,
	WooPaymentsOverviewTasksVisibility,
} from '../types';
import { getVisibleOverviewTasks } from './overview-tasks';
import { saveOption } from '../../../settings/data/actions';

const DISMISSED_TASKS_OPTION = 'woocommerce_dismissed_todo_tasks';

export const OverviewTaskList = ( {
	tasks,
	visibility,
}: {
	tasks: WooPaymentsOverviewTask[];
	visibility: WooPaymentsOverviewTasksVisibility;
} ) => {
	// Client 11.3.0 `overview/task-list/index.js:56-104`: a dismissal hides the task at once and saves the list, and Undo puts it back.
	const [ dismissedTaskKeys, setDismissedTaskKeys ] = useState(
		visibility.dismissed_todo_tasks
	);
	const dismissedTaskKeysRef = useRef( dismissedTaskKeys );
	const saveDismissedTaskKeys = ( keys: string[] ) => {
		dismissedTaskKeysRef.current = keys;
		setDismissedTaskKeys( keys );
		void saveOption( DISMISSED_TASKS_OPTION, keys );
	};
	const dismissTask = ( key: string ) => {
		saveDismissedTaskKeys( [ ...dismissedTaskKeysRef.current, key ] );
		dispatch( 'core/notices' ).createSuccessNotice(
			__( 'Task dismissed', 'woocommerce' ),
			{
				actions: [
					{
						label: __( 'Undo', 'woocommerce' ),
						onClick: () =>
							saveDismissedTaskKeys(
								dismissedTaskKeysRef.current.filter(
									( dismissedKey ) => dismissedKey !== key
								)
							),
					},
				],
			}
		);
	};

	const visibleTasks = getVisibleOverviewTasks( tasks, {
		...visibility,
		dismissed_todo_tasks: dismissedTaskKeys,
	} );

	if ( visibleTasks.length === 0 ) {
		return null;
	}

	// Client 11.1.0 `overview/task-list/index.js:167-213` and `overview/index.js:346-357`: no visible heading.
	return (
		<Card
			as="section"
			className="woocommerce-woopayments-overview__tasks"
			aria-labelledby="woocommerce-woopayments-overview-tasks-heading"
		>
			<h2
				id="woocommerce-woopayments-overview-tasks-heading"
				className="screen-reader-text"
			>
				{ __( 'Things to do', 'woocommerce' ) }
			</h2>
			{ /* Client 11.3.0 `overview/task-list/index.js:166-173`: open at first, folding to five. */ }
			<CollapsibleList
				className="woocommerce-woopayments-overview__task-list"
				collapsed={ false }
				show={ 5 }
				collapseLabel={ __( 'Hide tasks', 'woocommerce' ) }
				expandLabel={ __( 'Show tasks', 'woocommerce' ) }
			>
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
								? () => dismissTask( task.key )
								: undefined
						}
					/>
				) ) }
			</CollapsibleList>
		</Card>
	);
};
