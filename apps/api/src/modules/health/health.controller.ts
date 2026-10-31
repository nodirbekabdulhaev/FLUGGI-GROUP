import { Controller, Get } from '@nestjs/common';
import { Public } from '../../core/auth/decorators';
import { AppException } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';

@Controller()
export class HealthController {
  constructor(private readonly prisma: PrismaService) {}

  @Get('health')
  @Public()
  health() {
    return { status: 'ok' };
  }

  @Get('ready')
  @Public()
  async ready() {
    try {
      await this.prisma.$queryRaw`SELECT 1`;
      return { status: 'ok', database: 'ok' };
    } catch {
      throw new AppException('INTERNAL', 'База данных недоступна');
    }
  }
}
