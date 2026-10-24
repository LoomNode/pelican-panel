<?php

namespace App\Http\Controllers\Api\Application\Servers;

use App\Enums\SuspendAction;
use App\Exceptions\DisplayException;
use App\Exceptions\Http\Server\NodeNotViableException;
use App\Exceptions\Model\DataValidationException;
use App\Http\Controllers\Api\Application\ApplicationApiController;
use App\Http\Requests\Api\Application\Servers\ServerWriteRequest;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\ReinstallServerService;
use App\Services\Servers\SuspensionService;
use App\Services\Servers\TransferServerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

#[Group('Server', weight: 4)]
class ServerManagementController extends ApplicationApiController
{
    /**
     * ServerManagementController constructor.
     */
    public function __construct(
        private ReinstallServerService $reinstallServerService,
        private SuspensionService $suspensionService,
        private TransferServerService $transferServerService,
        private DaemonServerRepository $daemonServerRepository,
    ) {
        parent::__construct();
    }

    /**
     * Suspend
     *
     * Suspend a server on the Panel.
     *
     * @throws Throwable
     */
    public function suspend(ServerWriteRequest $request, Server $server): Response
    {
        $this->suspensionService->handle($server, SuspendAction::Suspend);

        return $this->returnNoContent();
    }

    /**
     * Unsuspsend
     *
     * Unsuspend a server on the Panel.
     *
     * @throws Throwable
     */
    public function unsuspend(ServerWriteRequest $request, Server $server): Response
    {
        $this->suspensionService->handle($server, SuspendAction::Unsuspend);

        return $this->returnNoContent();
    }

    /**
     * Reinstall
     *
     * Mark a server as needing to be reinstalled.
     *
     * @throws DisplayException
     * @throws DataValidationException
     */
    public function reinstall(ServerWriteRequest $request, Server $server): Response
    {
        $this->reinstallServerService->handle($server);

        return $this->returnNoContent();
    }

    /**
     * Power a server
     *
     * Sends a power signal (start, stop, restart or kill) to the server, like the
     * Client API's power endpoint, so an integration that manages servers with an
     * application key can start one it has just unsuspended without also holding a
     * user's client key. A suspended server can't be powered.
     *
     * @throws ConnectionException
     */
    public function power(ServerWriteRequest $request, Server $server): Response
    {
        $signal = $request->validate([
            'signal' => 'required|string|in:start,stop,restart,kill',
        ])['signal'];

        if ($server->isSuspended()) {
            throw new ConflictHttpException('Cannot send a power signal to a suspended server.');
        }

        $this->daemonServerRepository->setServer($server)->power($signal);

        return $this->returnNoContent();
    }

    /**
     * Start transfer
     *
     * Starts a transfer of a server to a new node.
     *
     * @throws NodeNotViableException
     */
    #[ResponseDoc(status: 406, description: 'Node was not viable')]
    public function startTransfer(ServerWriteRequest $request, Server $server): Response
    {
        $validatedData = $request->validate([
            'node_id' => 'required|exists:nodes,id',
            'allocation_id' => 'required|bail|unique:servers|exists:allocations,id',
            'allocation_additional' => 'nullable|array',
            'allocation_additional.*' => 'integer|exists:allocations,id',
        ]);

        $this->transferServerService->handle($server, Arr::get($validatedData, 'node_id'), Arr::get($validatedData, 'allocation_id'), Arr::get($validatedData, 'allocation_additional', []));

        /**
         * Transfer started
         *
         * @status 204
         */
        return $this->returnNoContent();
    }

    /**
     * Cancel transfer
     *
     * Cancels a transfer of a server to a new node.
     *
     * @throws ConnectionException
     */
    public function cancelTransfer(ServerWriteRequest $request, Server $server): Response
    {
        if (!$transfer = $server->transfer) {
            /**
             * Server is not transferring
             *
             * @status 406
             */
            return $this->returnNotAcceptable();
        }

        $transfer->successful = true;
        $transfer->save();

        $this->daemonServerRepository->setServer($server)->cancelTransfer();

        /**
         * Transfer cancelled
         *
         * @status 204
         */
        return $this->returnNoContent();
    }
}
